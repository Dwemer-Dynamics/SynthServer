<?php declare(strict_types=1);

require_once 'DatabaseTestCase.php';
require_once 'CallableMock.php';
require_once dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'worldknowledge_topic.php';
require_once dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'worldknowledge_catalog.php';

// setUp and tearDown for the test database are in DatabaseTestCase.php
final class WorldKnowledgeTest extends DatabaseTestCase
{
    public function testShippedFalloutWorldKnowledgeDatasetContract(): void
    {
        $root = dirname(__DIR__, 2);
        $sourcesPath = $root.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'fallout_worldknowledge_sources.jsonl';
        $catalog = synthWorldKnowledgeLoadFactoryCatalog($root);
        $topics = [];
        $aliasCount = 0;
        $categoryCounts = [];
        $nameOwners = [];
        foreach ($catalog['rows'] as $data) {
            $topic = strval($data['topic'] ?? '');
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $topic);
            $this->assertArrayNotHasKey($topic, $topics);
            $topics[$topic] = true;
            $topicKey = synthWorldKnowledgeComparableTopic($topic);
            $this->assertArrayNotHasKey($topicKey, $nameOwners);
            $nameOwners[$topicKey] = $topic;
            $aliases = synthWorldKnowledgeSplitAliases($data['aliases'] ?? '');
            $aliasCount += count($aliases);
            foreach ($aliases as $alias) {
                $aliasKey = synthWorldKnowledgeComparableTopic($alias);
                $this->assertArrayNotHasKey($aliasKey, $nameOwners);
                $nameOwners[$aliasKey] = $topic;
            }
            $this->assertNotEmpty($data['topic_desc']);
            $this->assertGreaterThanOrEqual(4, count(array_filter(array_map('trim', explode(',', $data['tags'])))));
            $category = strval($data['category']);
            $categoryCounts[$category] = intval($categoryCounts[$category] ?? 0) + 1;
        }

        $this->assertCount(15, $topics);
        $this->assertGreaterThanOrEqual(5, $aliasCount);
        $this->assertSame(
            ['location' => 5, 'person' => 2, 'faction' => 5, 'creature' => 3],
            $categoryCounts
        );
        $this->assertSame('approved', $catalog['manifest']['editorial_review']['status'] ?? null);
        $this->assertContains('Fallout 4', $catalog['manifest']['coverage']['games'] ?? []);

        $sourceTopics = [];
        foreach (file($sourcesPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $source = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $sourceTopics[synthWorldKnowledgeCanonicalTopic($source['topic'] ?? '')] = true;
            $this->assertNotEmpty($source['source_url'] ?? '');
            $this->assertNotEmpty($source['revision_id'] ?? '');
        }
        $this->assertSame(array_keys($topics), array_keys($sourceTopics));
    }

    public function testWorldKnowledgeSchemaSupportsCustomOverridesWithoutChangingFactoryRows(): void
    {
        $testDb = new sql();
        $column = $testDb->fetchOne("
            SELECT is_nullable
              FROM information_schema.columns
             WHERE table_schema = 'synth'
               AND table_name = 'worldknowledge'
               AND column_name = 'topic_desc'
        ");
        $uniqueIndex = $testDb->fetchOne("
            SELECT COUNT(*) AS total
              FROM pg_indexes
             WHERE schemaname = 'synth'
               AND tablename = 'worldknowledge'
               AND indexname = 'worldknowledge_custom_topic_unique_idx'
        ");
        $legacyUniqueIndexes = $testDb->fetchOne("
            SELECT COUNT(*) AS total FROM pg_indexes
             WHERE schemaname = 'synth'
               AND indexname IN ('worldknowledge_topic_unique_idx', 'worldknowledge_canonical_topic_unique_idx')
        ");
        $factoryBefore = $testDb->fetchOne("SELECT COUNT(*) AS total FROM worldknowledge WHERE source_kind='factory'");
        $firstImport = $testDb->execQuery("
            INSERT INTO worldknowledge (topic, aliases, canonical_topic, topic_desc_basic, category, source_kind, is_active)
            VALUES ('diamond_city', 'The Great Green Jewel', 'diamond_city', 'Diamond City is a fortified Commonwealth settlement.', 'location', 'custom', TRUE)
            ON CONFLICT (canonical_topic) WHERE source_kind='custom' AND is_active DO UPDATE
                SET topic = EXCLUDED.topic,
                    topic_desc_basic = EXCLUDED.topic_desc_basic,
                    category = EXCLUDED.category
        ");
        $secondImport = $testDb->execQuery("
            INSERT INTO worldknowledge (topic, aliases, canonical_topic, topic_desc_basic, category, source_kind, is_active)
            VALUES ('diamond_city', 'The Stadium', 'diamond_city', 'Diamond City occupies Boston''s old baseball stadium.', 'location', 'custom', TRUE)
            ON CONFLICT (canonical_topic) WHERE source_kind='custom' AND is_active DO UPDATE
                SET topic_desc_basic = EXCLUDED.topic_desc_basic,
                    topic = EXCLUDED.topic,
                    aliases = EXCLUDED.aliases,
                    category = EXCLUDED.category
        ");
        $imported = $testDb->fetchOne("
            SELECT COUNT(*) AS total, MAX(topic) AS topic, MAX(aliases) AS aliases,
                   MAX(topic_desc_basic) AS topic_desc_basic, MAX(source_kind) AS source_kind
              FROM worldknowledge_effective WHERE canonical_topic = 'diamond_city'
        ");
        $factoryAfter = $testDb->fetchOne("SELECT COUNT(*) AS total FROM worldknowledge WHERE source_kind='factory'");
        $testDb->close();

        $this->assertSame('YES', $column['is_nullable'] ?? null);
        $this->assertSame(1, intval($uniqueIndex['total'] ?? 0));
        $this->assertSame(0, intval($legacyUniqueIndexes['total'] ?? -1));
        $this->assertNotFalse($firstImport);
        $this->assertNotFalse($secondImport);
        $this->assertSame(1, intval($imported['total'] ?? 0));
        $this->assertSame('diamond_city', $imported['topic'] ?? null);
        $this->assertSame('The Stadium', $imported['aliases'] ?? null);
        $this->assertSame('custom', $imported['source_kind'] ?? null);
        $this->assertSame('Diamond City occupies Boston\'s old baseball stadium.', $imported['topic_desc_basic'] ?? null);
        $this->assertSame(intval($factoryBefore['total'] ?? -1), intval($factoryAfter['total'] ?? -2));
    }

    public function testWorldKnowledge_WhenNoKeywordMatch_ContextShouldNotContainLore(): void
    {
        // default test config
        require("conf.php");

        $this->insertStimpackLore();

        $GLOBALS["mockMinimeTopic"] = function($text) {
            return '{"input_text": "'.$text.'", "generated_tags": "", "elapsed_time": "0.05 seconds"}';
        };

        // input topic = 0
        // worldknowledge topic = 0
        // location = 0
        // context = 0

        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->callback(function ($streamContext) {
                $options = stream_context_get_options($streamContext);
                $this->assertStringNotContainsString('<knowledge>', $options['http']['content']);
                return true;
            })
        )
        ->willReturnCallback(function($url, $context) {
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('inputtext', 100, 200, 'What\'s going on around here?');
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
    }

    public function testWorldKnowledge_WhenInputTopicKeywordsFound_ContextShouldContainLore(): void
    {
        // default test config
        require("conf.php");

        $this->insertStimpackLore();

        // input topic = 6.9
        // worldknowledge topic = 0
        // location = 0
        // context = 0

        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->callback(function ($streamContext) {
                $options = stream_context_get_options($streamContext);
                $this->assertStringContainsString('- Topic: stimpack_seller', $options['http']['content']);
                $this->assertStringContainsString('- Access: advanced', $options['http']['content']);
                $this->assertStringContainsString("The stimpack vendor is a wasteland medic who buys and sells stimpaks", $options['http']['content']);
                return true;
            })
        )
        ->willReturnCallback(function($url, $context) {
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('inputtext', 100, 200, 'Tell me about the stimpack vendor.');
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
    }

    public function testWorldKnowledge_WhenOnlyBasicDescriptionExists_ContextShouldContainBasicLore(): void
    {
        require("conf.php");

        $testDb = new sql();
        $testDb->execQuery("DELETE FROM worldknowledge WHERE canonical_topic = 'the_minutemen' AND source_kind = 'custom'");
        $testDb->insert(
            'worldknowledge',
            array(
                'topic' => 'the_minutemen',
                'aliases' => 'Minutemen',
                'canonical_topic' => 'the_minutemen',
                'source_kind' => 'custom',
                'is_active' => true,
                'topic_desc_basic' => 'The Minutemen are a volunteer militia that protects Commonwealth settlements.'
            )
        );
        $testDb->execQuery("
            UPDATE worldknowledge
               SET native_vector =
                     setweight(to_tsvector(coalesce(topic, '')), 'A')
                  || setweight(to_tsvector(coalesce(topic_desc, '')), 'B')
                  || setweight(to_tsvector(coalesce(topic_desc_basic, '')), 'C')
             WHERE canonical_topic = 'the_minutemen' AND source_kind = 'custom'
        ");
        $testDb->close();

        $GLOBALS["mockMinimeTopic"] = static function ($text) {
            return json_encode([
                'input_text' => $text,
                'generated_tags' => 'Minutemen',
                'elapsed_time' => '0.05 seconds',
            ]);
        };

        $GLOBALS["mockConnectorSend"] = $this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
            ->method('__invoke')
            ->with(
                $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
                $this->callback(function ($streamContext) {
                    $options = stream_context_get_options($streamContext);
                    $this->assertStringContainsString('- Access: basic', $options['http']['content']);
                    $this->assertStringContainsString('The Minutemen are a volunteer militia', $options['http']['content']);
                    $this->assertStringNotContainsString('the_minutemen,Minutemen', $options['http']['content']);
                    $this->assertStringNotContainsString('- Access: advanced', $options['http']['content']);
                    return true;
                })
            )
            ->willReturnCallback(function ($url, $context) {
                return $this->defaultConnectorResponse($url, $context);
            });

        $this->setJsonRequest(
            'inputtext',
            100,
            200,
            'Tell me about the Minutemen.',
            ['skip_player_tts' => true]
        );
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
    }

    public function testWorldKnowledge_WhenWorldKnowledgeTopicKeywordsFound_ContextShouldContainLore(): void
    {
        // default test config
        require("conf.php");

        $this->insertStimpackLore();

        $testDb = new sql();
        $testDb->insert(
            'conf_opts',
            array(
                'id' => 'current_worldknowledge_topic',
                'value' => 'Stimpack Vendor'
            )
        );
        $testDb->close();

        // input topic = 0
        // worldknowledge topic = 3.4
        // location = 0
        // context = 0

        $capturedContent = '';
        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->anything()
        )
        ->willReturnCallback(function($url, $context) use (&$capturedContent) {
            $capturedContent = stream_context_get_options($context)['http']['content'] ?? '';
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('inputtext', 100, 200, 'I carried a fusion core. Surely I must know something.');
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
        $this->assertStringContainsString("- Access: advanced", $capturedContent);
        $this->assertStringContainsString("The stimpack vendor is a wasteland medic who buys and sells stimpaks", $capturedContent);
    }

    public function testWorldKnowledge_WhenInsufficientWorldKnowledgeTopicKeywordsFound_ContextShouldNotContainLore(): void
    {
        // default test config
        require("conf.php");

        $this->insertStimpackLore();

        $testDb = new sql();
        $testDb->insert(
            'conf_opts',
            array(
                'id' => 'current_worldknowledge_topic',
                'value' => 'Alchemist'
            )
        );
        $testDb->close();

        // input topic = 0
        // worldknowledge topic = 1.2
        // location = 0
        // context = 0

        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->callback(function ($streamContext) {
                $options = stream_context_get_options($streamContext);
                $this->assertStringNotContainsString('topic=\\"stimpack_seller\\"', $options['http']['content']);
                $this->assertStringNotContainsString('The stimpack vendor is a wasteland medic', $options['http']['content']);
                return true;
            })
        )
        ->willReturnCallback(function($url, $context) {
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('inputtext', 100, 200, 'I carried a fusion core. Surely I must know something.');
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
    }

    public function testWorldKnowledge_WhenWorldKnowledgeTopicPlusLocationKeywordsFound_ContextShouldContainLore(): void
    {
        // default test config
        require("conf.php");

        $this->insertStimpackLore();
        $testDb = new sql();
        $testDb->insert(
            'conf_opts',
            array(
                'id' => 'current_worldknowledge_topic',
                'value' => 'Stimpack'
            )
        );
        $testDb->insert(
            'eventlog',
            array(
                'ts' => "0",
                'gamets' => "0",
                'type' => "world_context",
                'data' => "world context: Lair of the Stimpack Vendor",
                'sess' => 'pending',
                'localts' => 0,
                'location'=> "Lair of the Stimpack Vendor",
                'party'=> '{"type":"world_context","location":"Lair of the Stimpack Vendor","worldspace":"Seller Of Stimpaks","game_time":{"year":2281,"month":11,"day":30,"hour":14.3333}}'
            )
        );
        $testDb->close();

        // input topic = 0
        // worldknowledge topic = 3.6 (stimpack_of_pickpocketing wins without the eventlog entry)
        // location = 1.3
        // context = 0

        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->callback(function ($streamContext) {
                $options = stream_context_get_options($streamContext);
                $this->assertStringNotContainsString('topic=\\"stimpack_seller\\"', $options['http']['content']);
                return true;
            })
        )
        ->willReturnCallback(function($url, $context) {
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('inputtext', 100, 200, 'I carried a fusion core. Surely I must know something.');
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
    }

    public function testWorldKnowledge_WhenWorldKnowledgeTopicPlusLocationPlusContextKeywordsFound_ContextShouldContainLore(): void
    {
        // default test config
        require("conf.php");

        $this->insertStimpackLore();
        $testDb = new sql();
        $testDb->insert(
            'conf_opts',
            array(
                'id' => 'current_worldknowledge_topic',
                'value' => 'Doctor'
            )
        );
        $testDb->insert(
            'eventlog',
            array(
                'ts' => "0",
                'gamets' => "0",
                'type' => "world_context",
                'data' => "world context: Lair of the Stimpack Vendor",
                'sess' => 'pending',
                'localts' => 0,
                'location'=> "Lair of the Stimpack Vendor",
                'party'=> '{"type":"world_context","location":"Lair of the Stimpack Vendor","worldspace":"Stimpack Vendor\'s Lair","game_time":{"year":2281,"month":11,"day":30,"hour":14.3333}}'
            )
        );
        $testDb->insert(
            'speech',
            array(
                'sess' => 'pending',
                'speaker' => 'Sole Survivor',
                'speech' => "tell me about the stimpack vendor.",
                'location' => "Diamond City",
                'listener' => "The Narrator",
                'localts' => 0,
                'gamets' => 0
            )
        );
        $testDb->insert(
            'speech',
            array(
                'sess' => 'pending',
                'speaker' => 'The Narrator',
                // manipulating the speech so that the tags are scored highly enough to pull in the worldknowledge topic
                'speech' => "Wasteland medic buys sells stimpaks doctors bags basic chems reserves supplies caps injuries caravan guards trade routes",
                'location' => "Diamond City",
                'listener' => "Sole Survivor",
                'localts' => 10,
                'gamets' => 10
            )
        );
        $testDb->close();

        $GLOBALS["mockMinimeTopic"] = function($text) {
            return '{"input_text": "'.$text.'", "generated_tags": "stimpack vendor", "elapsed_time": "0.05 seconds"}';
        };

        // input topic = 0
        // worldknowledge topic = 1.5
        // location = 1.4
        // context = 0.4

        $capturedContent = '';
        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->anything()
        )
        ->willReturnCallback(function($url, $context) use (&$capturedContent) {
            $capturedContent = stream_context_get_options($context)['http']['content'] ?? '';
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('inputtext', 100, 200, 'I carried a fusion core. Surely I must know something.');
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
        $this->assertStringContainsString("- Access: advanced", $capturedContent);
        $this->assertStringContainsString("The stimpack vendor is a wasteland medic who buys and sells stimpaks", $capturedContent);
    }

    private function expectPromptInContext($streamContext, $expectedPrompt) {
        $options = stream_context_get_options($streamContext);
        $content = json_decode($options['http']['content']);
        $found=false;
        foreach ($content->messages as $message) {
            if (json_encode($message) === json_encode($expectedPrompt)) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found);
    }

    private function expectPromptNotInContext($streamContext, $expectedPrompt) {
        $options = stream_context_get_options($streamContext);
        $content = json_decode($options['http']['content']);
        $found=false;
        foreach ($content->messages as $message) {
            if (json_encode($message) === json_encode($expectedPrompt)) {
                $found = true;
                break;
            }
        }

        $this->assertFalse($found);
    }

    private function defaultConnectorResponse($url, $context) {
        $response = 'data: {"choices":[{"delta":{"content": "{\"character\": \"The Narrator\", \"listener\": \"Sole Survivor\", \"message\": \"Unit test message\", \"mood\": \"default\", \"action\": \"Talk\", \"target\": \"Sole Survivor\"}"}}]}';
        $resourceMock = fopen('php://temp', 'r+');
        fwrite($resourceMock, $response);
        rewind($resourceMock);
        return $resourceMock;
    }

    private function insertStimpackLore() {
        $testDb = new sql();
        $testDb->insert(
            'worldknowledge',
            array(
                'topic' => 'stimpack_seller',
                'aliases' => 'stimpack vendor,Lair of the Stimpack Vendor',
                'canonical_topic' => 'stimpack_seller',
                'source_kind' => 'custom',
                'topic_desc' => 'The stimpack vendor is a wasteland medic who buys and sells stimpaks, doctor\'s bags, and basic chems. He reserves his best supplies for customers with caps or serious injuries. He respects caravan guards because they keep trade routes open.',
                'native_vector' => "'wasteland':4B 'medic':5B 'buy':8B 'sell':10B 'stimpack':1A,11B 'doctor':12B 'bag':13B 'chem':16B 'reserve':18B 'caps':26B 'injuri':29B 'caravan':33B 'guard':34B 'route':38B 'vendor':2A,3B"
            )
        );
        $testDb->close();
    }
}

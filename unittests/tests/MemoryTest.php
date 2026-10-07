<?php declare(strict_types=1);

require_once 'DatabaseTestCase.php';
require_once 'CallableMock.php';

// setUp and tearDown for the test database are in DatabaseTestCase.php
final class MemoryTest extends DatabaseTestCase
{
    public function testMemorySearchInputRemovesConversationRoutingLabels(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/data_functions.php';
        $originalPlayerName = $GLOBALS['PLAYER_NAME'] ?? null;
        $GLOBALS['PLAYER_NAME'] = 'Sole Survivor';

        $cases = [
            'Sole Survivor: Ask about the settlement. (Talking to Piper)',
            'Sole Survivor: Ask about the settlement. (Whispering to Piper)',
            'Sole Survivor: Ask about the settlement. (Shouting to Piper)',
            'Sole Survivor: Ask about the settlement. (Speaking privately to Piper)',
            'Sole Survivor: Ask about the settlement. (Context location: Diamond City)',
            'Sole Survivor: Ask about the settlement. Talking to The Narrator',
        ];

        foreach ($cases as $input) {
            $this->assertSame('Ask about the settlement.', synthNormalizeMemorySearchInput($input));
        }

        if ($originalPlayerName === null) {
            unset($GLOBALS['PLAYER_NAME']);
        } else {
            $GLOBALS['PLAYER_NAME'] = $originalPlayerName;
        }
    }

    public function testIndividualMemoryUsesGlobalBankUntilScopedSummaryExists(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/data_functions.php';

        $GLOBALS['db'] = new sql();
        $npcMaster = new NpcMaster();
        $this->assertTrue($npcMaster->create([
            'npc_name' => 'Scoped Memory Readiness Test',
            'individual_memory_enabled' => 1,
        ]));

        $this->assertSame(
            "(scope IS NULL OR scope='global')",
            dataGetMemoryScopeConditionSql('Scoped Memory Readiness Test')
        );

        $this->assertTrue($GLOBALS['db']->insert('memory_summary', [
            'gamets_truncated' => 100,
            'n' => 1,
            'packed_message' => 'Scoped memory source',
            'summary' => 'Scoped memory summary',
            'classifier' => 'individual',
            'uid' => 100,
            'scope' => 'Scoped Memory Readiness Test',
        ]));

        $this->assertSame(
            "scope='Scoped Memory Readiness Test'",
            dataGetMemoryScopeConditionSql('Scoped Memory Readiness Test')
        );
    }

    public function testMemory_WhenDumbContextAndNoMemoryExists_ContextShouldNotContainMemory(): void
    {
        // default test config
        require("conf.php");
        $GLOBALS["MINIME_T5"] = false;
        $this->useFullTextMemorySearch();

        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->callback(function ($streamContext) {
                $options = stream_context_get_options($streamContext);
                $this->assertStringNotContainsString("remembers this:", $options['http']['content']);
                return true;
            })
        )
        ->willReturnCallback(function($url, $context) {
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('inputtext', 100, 200, 'Hopefully we can buy some ale in town.');
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
    }

    public function testMemory_WhenDumbContextOffersNoMemory_ContextShouldNotContainMemory(): void
    {
        // default test config
        require("conf.php");
        $GLOBALS["MINIME_T5"] = false;
        $this->useFullTextMemorySearch();

        // add summarized memory
        $this->insertStimpackMemory();

        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->callback(function ($streamContext) {
                $options = stream_context_get_options($streamContext);
                $this->assertStringNotContainsString("remembers this:", $options['http']['content']);
                return true;
            })
        )
        ->willReturnCallback(function($url, $context) {
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('inputtext', 100, 200, 'Hopefully we can buy some ale in town.');
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
    }

    public function testMemory_WhenDumbContextOffersMemory_ContextShouldContainMemory(): void
    {
        // default test config
        require("conf.php");
        $GLOBALS["MINIME_T5"] = false;
        $this->useFullTextMemorySearch();

        // add summarized memory
        $this->insertStimpackMemory();

        $GLOBALS["mockMinimeExtract"] = function($text) {
            return '{"is_memory_recall": "Yes", "generated_tags": "Stimpaks|Town", "elapsed_time": "0.05 seconds"}';
        };

        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->callback(function ($streamContext) {
                $content = stream_context_get_options($streamContext)['http']['content'];
                $this->assertStringContainsString('The Narrator remembers this:', $content);
                $this->assertStringContainsString('Sole Survivor attempted to buy strong stimpaks', $content);
                return true;
            })
        )
        ->willReturnCallback(function($url, $context) {
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('inputtext', 100, 35000000, 'Hopefully we can buy some stimpaks in town.');
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
    }

    public function testMemory_WhenMinimeT5AndNoMemoryExists_ContextShouldNotContainMemory(): void
    {
        // default test config
        require("conf.php");
        $this->useFullTextMemorySearch();

        $GLOBALS["mockMinimeExtract"] = function($text) {
            return '{"is_memory_recall": "Yes", "generated_tags": "Ale|Town", "elapsed_time": "0.05 seconds"}';
        };

        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->callback(function ($streamContext) {
                $options = stream_context_get_options($streamContext);
                $this->assertStringNotContainsString("remembers this:", $options['http']['content']);
                return true;
            })
        )
        ->willReturnCallback(function($url, $context) {
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('inputtext', 100, 200, 'Hopefully we can buy some ale in town.');
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
    }

    public function testMemory_WhenMinimeT5OffersNoMemory_ContextShouldNotContainMemory(): void
    {
        // default test config
        require("conf.php");
        $this->useFullTextMemorySearch();

        // add summarized memory
        $this->insertStimpackMemory();

        $GLOBALS["mockMinimeExtract"] = function($text) {
            return '{"is_memory_recall": "Yes", "generated_tags": "Ale|Town", "elapsed_time": "0.05 seconds"}';
        };

        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->callback(function ($streamContext) {
                $options = stream_context_get_options($streamContext);
                $this->assertStringNotContainsString("remembers this:", $options['http']['content']);
                return true;
            })
        )
        ->willReturnCallback(function($url, $context) {
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('inputtext', 100, 200, 'Hopefully we can buy some ale in town.');
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
    }

    public function testMemory_WhenMinimeT5OffersMemory_ContextShouldContainMemory(): void
    {
        // default test config
        require("conf.php");
        $this->useFullTextMemorySearch();

        // add summarized memory
        $this->insertStimpackMemory();

        $GLOBALS["mockMinimeExtract"] = function($text) {
            return '{"is_memory_recall": "Yes", "generated_tags": "Stimpaks|Town", "elapsed_time": "0.05 seconds"}';
        };

        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->callback(function ($streamContext) {
                $content = stream_context_get_options($streamContext)['http']['content'];
                $this->assertStringContainsString('The Narrator remembers this:', $content);
                $this->assertStringContainsString('Sole Survivor attempted to buy strong stimpaks', $content);
                return true;
            })
        )
        ->willReturnCallback(function($url, $context) {
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('inputtext', 100, 35000000, 'Hopefully we can buy some stimpaks in town.');
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
    }

    public function testMemory_WhenNotPlayerInput_ContextShouldNotContainMemory(): void
    {
        // default test config
        require("conf.php");

        // add summarized memory
        $this->insertStimpackMemory();

        $testDb = new sql();
        $GLOBALS['db'] = $testDb;
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'npc_master.class.php';
        $npcMaster = new NpcMaster();
        $this->assertTrue($npcMaster->create([
            'npc_name' => $this->testNPCName,
            'personality' => 'You are a Unit Test.',
        ]));
        $testDb->close();
        unset($GLOBALS['db']);

        // should not be used, but if it is then generate tags
        $GLOBALS["mockMinimeExtract"] = function($text) {
            return '{"is_memory_recall": "Yes", "generated_tags": "Stimpaks|Town", "elapsed_time": "0.05 seconds"}';
        };

        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->callback(function ($streamContext) {
                $options = stream_context_get_options($streamContext);
                $this->assertStringNotContainsString("remembers this:", $options['http']['content']);
                return true;
            })
        )
        ->willReturnCallback(function($url, $context) {
            $response = 'data: {"choices":[{"delta":{"content": "{\"character\": \"Unit Test\", \"listener\": \"Sole Survivor\", \"message\": \"You should have tried buying some weaker stimpaks\", \"mood\": \"default\", \"action\": \"Talk\", \"target\": \"Sole Survivor\"}"}}]}';
            $resourceMock = fopen('php://temp', 'r+');
            fwrite($resourceMock, $response);
            rewind($resourceMock);
            return $resourceMock;
        });
        $this->setJsonRequest('bored', 100, 200, 'Unit Test');
        $GLOBALS['active_profile'] = md5($this->testNPCName);
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
    }

    private function defaultConnectorResponse($url, $context) {
        $response = 'data: {"choices":[{"delta":{"content": "{\"character\": \"The Narrator\", \"listener\": \"Sole Survivor\", \"message\": \"Unit test message\", \"mood\": \"default\", \"action\": \"Talk\", \"target\": \"Sole Survivor\"}"}}]}';
        $resourceMock = fopen('php://temp', 'r+');
        fwrite($resourceMock, $response);
        rewind($resourceMock);
        return $resourceMock;
    }

    // Keep the legacy full-text recall scenarios deterministic and independent of the vector service.
    private function useFullTextMemorySearch(): void
    {
        $GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["USE_TEXT2VEC"] = false;
        $testDb = new sql();
        $testDb->upsertRowOnConflict('general_settings', [
            'id' => 'FEATURES@MEMORY_EMBEDDING@USE_TEXT2VEC',
            'value' => 'false',
            'description' => 'Use deterministic PostgreSQL full-text memory recall in this test.',
        ], 'id');
        $testDb->close();
    }

    private function insertStimpackMemory() {
        $testDb = new sql();
        $this->assertTrue($testDb->insert(
            'memory_summary',
            array(
                'gamets_truncated' => 0,
                'n' => 0,
                'packed_message' => '(Context Location:Diamond City) Sole Survivor: Stimpack vendor. I need your strongest stimpaks.\n'.
                    '(Context Location:Diamond City) Stimpack Vendor: You can\'t handle my strongest stimpaks, traveler.',
                'summary' => '#Summary: Sole Survivor attempted to buy strong stimpaks from a merchant but was rudely turned away.\n\n'.
                    '#Tags: #StimpackSeller #Stimpaks',
                'uid' => 0,
                'companions' => 'Unit Test,Stimpack Vendor',
                'tags' => '#StimpackSeller #Stimpaks',
                'native_vec'=> "'stimpak':2A,9B,22B 'town':3A,10B 'seller':1A,21B"
            )
        ));
        $stored = $testDb->fetchOne("SELECT summary, native_vec::text AS native_vec FROM memory_summary WHERE uid=0 LIMIT 1");
        $this->assertStringContainsString('stimpak', strval($stored['native_vec'] ?? ''));
        $testDb->close();
    }
}

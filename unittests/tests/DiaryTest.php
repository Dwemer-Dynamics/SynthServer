<?php declare(strict_types=1);

require_once 'DatabaseTestCase.php';
require_once 'CallableMock.php';

// setUp and tearDown for the test database are in DatabaseTestCase.php
final class DiaryTest extends DatabaseTestCase
{
    public function testDiary_EmptyContentInContext_ShouldBeStrippedFromDiaryPrompt(): void
    {
        // default test config
        require("conf.php");
        $GLOBALS["SYNTH_NAME"]="Unit Test";
        $GLOBALS["SYNTH_PERS"]="You are a Unit Test.";
        $GLOBALS["CONNECTORS"]=["openrouterjson"];

        // add dialogue history
        $testDb = new sql();
        $testDb->insert(
            'eventlog',
            array(
                'ts' => "0",
                'gamets' => "0",
                'type' => "inputtext",
                'data' => "Prisoner:Silence. (Talking to Unit Test)",
                'sess' => 'pending',
                'localts' => 0,
                'people'=> "|Unit Test|",
                'location'=> "",
                'party'=> "[]"
            )
        );
        $testDb->insert(
            'eventlog',
            array(
                'ts' => "0",
                'gamets' => "0",
                'type' => "chat",
                'data' => "", // empty content
                'sess' => 'pending',
                'localts' => 2,
                'people'=> "|Unit Test|",
                'location'=> "",
                'party'=> "[]"
            )
        );
        $GLOBALS['db'] = $testDb;
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'npc_master.class.php';
        $npcMaster = new NpcMaster();
        $this->assertTrue($npcMaster->create([
            'npc_name' => $this->testNPCName,
            'personality' => 'You are a Unit Test.',
        ]));
        $testDb->close();
        unset($GLOBALS['db']);

        $GLOBALS["mockConnectorSend"]=$this->createMock(CallableMock::class);
        $GLOBALS["mockConnectorSend"]->expects($this->once())
        ->method('__invoke')
        ->with(
            $this->equalTo('https://openrouter.ai/api/v1/chat/completions'),
            $this->callback(function ($streamContext) {
                $expectedPrompt = ["role"=>"user", "content"=>""];
                $this->expectPromptNotInContext($streamContext, $expectedPrompt);

                $options = stream_context_get_options($streamContext);
                $content = json_decode($options['http']['content']);
                foreach ($content->messages as $message) {
                    if (($message->role ?? '') === 'user'
                        && str_contains((string)($message->content ?? ''), "Unit Test's diary")) {
                        return true;
                    }
                }

                $this->fail('The NPC diary prompt was not included in the connector context.');
            })
        )
        ->willReturnCallback(function($url, $context) {
            return $this->defaultConnectorResponse($url, $context);
        });
        $this->setJsonRequest('diary', 100, 200, 'Unit Test');
        $GLOBALS["active_profile"] = md5($this->testNPCName);
        require(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."main.php");
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
        $response = '{"choices":[{"message":{"content":"Diary content here"}}],"usage":{}}';
        $resourceMock = fopen('php://temp', 'r+');
        fwrite($resourceMock, $response);
        rewind($resourceMock);
        return $resourceMock;
    }
}

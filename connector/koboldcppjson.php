<?php
require_once __DIR__ . '/../lib/provider_stream.php';

/* kolboldcpp connector */

class koboldcppjson
{
    public $primary_handler;
    public $name;

    public $_functionMode;
    public $_extractedbuffer;
    private $_ignoreRest;
    private $_functionRawName;
    private $_functionName;
    private $_parameterBuff;
    private $_commandBuffer;
    private $_jsonMode;
    private $_jsonBuffer;

    public function __construct()
    {
        $this->name="koboldcppjson";
        $this->_ignoreRest=false;
        $this->_jsonMode=true; 
        $this->_extractedbuffer="";
        require_once(__DIR__."/__jpd.php");
         
    }


    // Background generation uses KoboldCpp's chat API: preserve supplied messages without dialogue JSON/action templates.
    public function fast_request($contextData, $customParms, $callName = '')
    {
        $settings = $GLOBALS['CONNECTOR'][$this->name] ?? [];
        $url = rtrim(strval($settings['url'] ?? ''), '/').'/v1/chat/completions';
        if (!in_array(strtolower(strval(parse_url($url, PHP_URL_SCHEME))), ['http','https'], true)) {
            throw new InvalidArgumentException('KoboldCpp URL must use HTTP or HTTPS.');
        }
        if (!is_array($contextData) || !array_is_list($contextData) || !$contextData) {
            throw new InvalidArgumentException('KoboldCpp messages unavailable.');
        }
        foreach ($contextData as $message) {
            if (!is_array($message) || array_diff(array_keys($message), ['role','content'])
                || !in_array($message['role'] ?? '', ['system','user','assistant'], true)
                || !is_string($message['content'] ?? null) || str_contains($message['content'], "\0")
                || !preg_match('//u', $message['content'])) throw new InvalidArgumentException('Invalid KoboldCpp message.');
        }
        $tokens = intval($GLOBALS['FORCE_MAX_TOKENS'] ?? $customParms['MAX_TOKENS'] ?? $settings['max_tokens'] ?? 512);
        if ($tokens < 1) throw new InvalidArgumentException('Invalid KoboldCpp token limit.');
        $data = [
            'messages'=>$contextData, 'stream'=>false, 'max_tokens'=>$tokens,
            'temperature'=>floatval($settings['temperature'] ?? 0.9),
            'top_p'=>floatval($settings['top_p'] ?? 0.9),
            'min_p'=>floatval($settings['min_p'] ?? 0), 'top_k'=>intval($settings['top_k'] ?? 0),
            'rep_pen'=>floatval($settings['rep_pen'] ?? 1.12),
        ];
        // Sampling extensions remain configurable; request ownership, output format and frozen token limits do not.
        foreach (synthGetEnabledConnectorExtraParameters($settings) as $key=>$value) {
            if (!in_array($key, ['messages','prompt','memory','system','model','stream','max_tokens','max_completion_tokens','max_length',
                'tools','functions','tool_choice','function_call','response_format','grammar'], true)) $data[$key]=$value;
        }
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($body)>1048576) throw new LengthException('KoboldCpp request exceeds its bound.');
        $headers = ['Content-Type: application/json'];
        if (!empty($settings['API_KEY'])) $headers[] = 'Authorization: Bearer '.$settings['API_KEY'];
        $context = stream_context_create(['http'=>[
            'method'=>'POST', 'header'=>implode("\r\n", $headers), 'content'=>$body,
            'timeout'=>max(1, min(120, intval($GLOBALS['HTTP_TIMEOUT'] ?? 30))),
        ]]);
        $responseHeaders = [];
        $body = synth_provider_fetch($url, $context, $responseHeaders, 1048576);
        if ($body === false) throw new RuntimeException('KoboldCpp generation failed.');
        if (strlen($body)>1048576) throw new LengthException('KoboldCpp response exceeds its bound.');
        $response = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $choice = $response['choices'][0] ?? [];
        $text = $choice['message']['content'] ?? null;
        if (!is_string($text) || trim($text)==='' || strlen($text)>65536 || str_contains($text,"\0")
            || !empty($choice['message']['tool_calls']) || !empty($choice['message']['function_call'])) {
            throw new RuntimeException('KoboldCpp returned no usable plain text.');
        }
        return trim($text);
    }

    public function open(&$inputContextData, $customParms)
    {
        // Keep the caller's owned history intact; all templates receive the same native tool evidence.
        $contextData = synth_provider_template_context($inputContextData);
        $normalizedContext = [];
        $instruction = '';
        $InstIns = false;
        $path='/api/extra/generate/stream/';
        $url=$GLOBALS["CONNECTOR"][$this->name]["url"].$path;
        $context="";

        
        $GLOBALS["PROMPT_HEAD"]=strtr($GLOBALS["PROMPT_HEAD"],["#PLAYER_NAME#"=>$GLOBALS["PLAYER_NAME"]]);
        $GLOBALS["SYNTH_PERS"]=strtr($GLOBALS["SYNTH_PERS"],["#PLAYER_NAME#"=>$GLOBALS["PLAYER_NAME"]]);

        foreach ($contextData as $n=>$s_msg) {	// Have to mangle context format

            if (!isset($s_msg["content"])) {
                Logger::debug("Entry $n without content");
                continue;
            }
            
            if (empty(trim($s_msg["content"]))) {
                unset($contextData[$n]);
                continue;
            } else {
                // This should be customizable per model
                /*
                if ($s_msg["role"]=="user")
                    $normalizedContext[]="### Instruction: ".$s_msg["content"];
                else if ($s_msg["role"]=="assistant")
                    $normalizedContext[]="### Response: ".$s_msg["content"];
                else if ($s_msg["role"]=="system")
                    $normalizedContext[]=$s_msg["content"];
                */
                $normalizedContext[]=$s_msg["content"];
            }
        }

        $stop_sequence=["{$GLOBALS["PLAYER_NAME"]}:","\n{$GLOBALS["PLAYER_NAME"]} ","Author's notes","###"];

        require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."functions".DIRECTORY_SEPARATOR."json_response.php");

        if (function_exists('synthEnsureNarratorJsonResponseState')) {
            synthEnsureNarratorJsonResponseState('KOBOLDCPPJSON');
        }

        /* This is handled in the template files
        if (isset($GLOBALS["FUNCTIONS_ARE_ENABLED"]) && $GLOBALS["FUNCTIONS_ARE_ENABLED"]) {
            $contextData[0]["content"].=$GLOBALS["COMMAND_PROMPT"];
        }
        */

        if (isset($GLOBALS["PATCH_PROMPT_ENFORCE_ACTIONS"]) && $GLOBALS["PATCH_PROMPT_ENFORCE_ACTIONS"]) {
            $prefix="{$GLOBALS["COMMAND_PROMPT_ENFORCE_ACTIONS"]}";
        }
        $prefix="{$GLOBALS["COMMAND_PROMPT_ENFORCE_ACTIONS"]}";

        if (strpos($GLOBALS["SYNTH_PERS"],"#SpeechStyle")!==false) {
            $speechReinforcement="Use #SpeechStyle. ";
        } else
            $speechReinforcement="";

        $contextData[]=[
            'role' => 'user',
            'content' => "{$prefix}. $speechReinforcement Use this JSON object to give your answer: ".json_encode($GLOBALS["responseTemplate"])
        ];
        
        if ($GLOBALS["CONNECTOR"][$this->name]["template"]=="alpaca") {
           
            include(__DIR__.DIRECTORY_SEPARATOR."templates".DIRECTORY_SEPARATOR."{$GLOBALS["CONNECTOR"][$this->name]["template"]}.php");


        } elseif ($GLOBALS["CONNECTOR"][$this->name]["template"]=="vicuna-1") {

            include(__DIR__.DIRECTORY_SEPARATOR."templates".DIRECTORY_SEPARATOR."{$GLOBALS["CONNECTOR"][$this->name]["template"]}.php");
           

        }  elseif ($GLOBALS["CONNECTOR"][$this->name]["template"]=="vicuna-1.1") {

            include(__DIR__.DIRECTORY_SEPARATOR."templates".DIRECTORY_SEPARATOR."{$GLOBALS["CONNECTOR"][$this->name]["template"]}.php");


        } elseif ($GLOBALS["CONNECTOR"][$this->name]["template"]=="chatml") {

            include(__DIR__.DIRECTORY_SEPARATOR."templates".DIRECTORY_SEPARATOR."{$GLOBALS["CONNECTOR"][$this->name]["template"]}.php");


        } elseif ($GLOBALS["CONNECTOR"][$this->name]["template"]=="chatml-c") {

            include(__DIR__.DIRECTORY_SEPARATOR."templates".DIRECTORY_SEPARATOR."{$GLOBALS["CONNECTOR"][$this->name]["template"]}.php");


        } elseif ($GLOBALS["CONNECTOR"][$this->name]["template"]=="synthia") {


            include(__DIR__.DIRECTORY_SEPARATOR."templates".DIRECTORY_SEPARATOR."{$GLOBALS["CONNECTOR"][$this->name]["template"]}.php");



        } elseif ($GLOBALS["CONNECTOR"][$this->name]["template"]=="extended-alpaca") {

            include(__DIR__.DIRECTORY_SEPARATOR."templates".DIRECTORY_SEPARATOR."{$GLOBALS["CONNECTOR"][$this->name]["template"]}.php");


        } elseif ($GLOBALS["CONNECTOR"][$this->name]["template"]=="superHOT") {

            include(__DIR__.DIRECTORY_SEPARATOR."templates".DIRECTORY_SEPARATOR."{$GLOBALS["CONNECTOR"][$this->name]["template"]}.php");


        } elseif ($GLOBALS["CONNECTOR"][$this->name]["template"]=="zephyr") {

            include(__DIR__.DIRECTORY_SEPARATOR."templates".DIRECTORY_SEPARATOR."{$GLOBALS["CONNECTOR"][$this->name]["template"]}.php");


        } elseif ($GLOBALS["CONNECTOR"][$this->name]["template"]=="openchat") {

           
            include(__DIR__.DIRECTORY_SEPARATOR."templates".DIRECTORY_SEPARATOR."{$GLOBALS["CONNECTOR"][$this->name]["template"]}.php");

        } elseif ($GLOBALS["CONNECTOR"][$this->name]["template"]=="dreamgen") {

           
            include(__DIR__.DIRECTORY_SEPARATOR."templates".DIRECTORY_SEPARATOR."{$GLOBALS["CONNECTOR"][$this->name]["template"]}.php");

        } elseif (file_exists(__DIR__.DIRECTORY_SEPARATOR."templates".DIRECTORY_SEPARATOR."{$GLOBALS["CONNECTOR"][$this->name]["template"]}.php")) {

            Logger::info("Using template ".__DIR__.DIRECTORY_SEPARATOR."templates".DIRECTORY_SEPARATOR."{$GLOBALS["CONNECTOR"][$this->name]["template"]}.php");
            include(__DIR__.DIRECTORY_SEPARATOR."templates".DIRECTORY_SEPARATOR."{$GLOBALS["CONNECTOR"][$this->name]["template"]}.php");

        }

       
        
        $TEMPERATURE=((isset($GLOBALS["CONNECTOR"][$this->name]["temperature"]) ? $GLOBALS["CONNECTOR"][$this->name]["temperature"] : 0.9)+0);
        $REP_PEN=((isset($GLOBALS["CONNECTOR"][$this->name]["rep_pen"]) ? $GLOBALS["CONNECTOR"][$this->name]["rep_pen"] : 1.12)+0);
        $TOP_P=((isset($GLOBALS["CONNECTOR"][$this->name]["top_p"]) ? $GLOBALS["CONNECTOR"][$this->name]["top_p"] : 0.9)+0);

        $MAX_TOKENS=((isset($GLOBALS["CONNECTOR"][$this->name]["max_tokens"]) ? $GLOBALS["CONNECTOR"][$this->name]["max_tokens"] : 48)+0);


        if ($GLOBALS["CONNECTOR"][$this->name]["newline_as_stopseq"]) {
            $stop_sequence[]="\n";
        }

        if (isset($GLOBALS["more_stopseq"])) {
            foreach ($GLOBALS["more_stopseq"] as $stopseq)
                $stop_sequence[]=$stopseq;
        }

        
        ///
        $stop_sequence[]=$GLOBALS["CONNECTOR"][$this->name]["eos_token"];
        ///
        $postData = array(

            "prompt"=>$context,
            "temperature"=> $TEMPERATURE,
            "top_p"=>$TOP_P,
            //"max_context_length"=>2048,
            "max_length"=>$MAX_TOKENS,
            "min_p"=>$GLOBALS["CONNECTOR"][$this->name]["min_p"]+0,
            "top_k"=>$GLOBALS["CONNECTOR"][$this->name]["top_k"]+0,
            "rep_pen"=>$REP_PEN,
            "stop_sequence"=>$stop_sequence,
            "use_default_badwordsids"=>$GLOBALS["CONNECTOR"][$this->name]["use_default_badwordsids"],
            "trim_stop"=>true

        );

        if ((isset($GLOBALS["CONNECTOR"][$this->name]["eos_token"]))&&!empty($GLOBALS["CONNECTOR"][$this->name]["eos_token"])) {
                $eos_token_allow_grammar='| "'.$GLOBALS["CONNECTOR"][$this->name]["eos_token"].'"';
        } else
            $eos_token_allow_grammar='';

        $postData["grammar"]=$GLOBALS["grammar"];

        if (!$GLOBALS["CONNECTOR"][$this->name]["grammar"])
            unset($postData["grammar"]);


        if (isset($customParms["MAX_TOKENS"])) {
            if ($customParms["MAX_TOKENS"]==null) {
                unset($postData["max_length"]);
            } elseif ($customParms["MAX_TOKENS"]) {
                $postData["max_length"]=$customParms["MAX_TOKENS"]+0;
            }
        }

        if (isset($GLOBALS["FORCE_MAX_TOKENS"])) {
            if ($GLOBALS["FORCE_MAX_TOKENS"]==null) {
                unset($postData["max_length"]);
            } else {
                $postData["max_length"]=$GLOBALS["FORCE_MAX_TOKENS"]+0;
            }

        }

        foreach (synthGetEnabledConnectorExtraParameters($GLOBALS["CONNECTOR"][$this->name] ?? []) as $k => $v) {
                $postData[$k]=$v;
        }

        $GLOBALS["DEBUG_DATA"]["koboldcpp_prompt"]=$postData;

        $headers = array(
            'Content-Type: application/json'
        );

        $options = array(
            'http' => array(
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => json_encode($postData)
            )
        );
        error_reporting(E_ALL);
        $context = stream_context_create($options);


        file_put_contents(__DIR__."/../log/context_sent_to_llm.log",date(DATE_ATOM)."\n=\n".var_export($postData,true)."\n=\n", FILE_APPEND);
        $this->primary_handler = synth_provider_open($url, $context);
        if (!$this->primary_handler) {
            Logger::error("Unable to connect to koboldcpp backend!");
            return false;
        }


        // Initialize variables for response
        $responseHeaders = '';
        $responseBody = '';
        
        $this->_jsonBuffer="";
        
        return true;

    }


    public function process()
    {
        $line = synth_provider_line($this->primary_handler);
        $buffer="";
        $totalBuffer="";
        $mangledBuffer="";
        file_put_contents(__DIR__."/../log/debugStream.log", $line, FILE_APPEND);
       
        
        if (!empty($GLOBALS["PATCH"]["PREAPPEND"])) {
            $this->_jsonBuffer=$GLOBALS["PATCH"]["PREAPPEND"];
            $GLOBALS["PATCH"]["PREAPPEND"]="";
        }
        
        if (strpos($line, 'data: {') !== 0) {
            return "";
        }
        

        $data=json_decode(substr($line, 6), true);


        if (!$data)
            return "";
        
        
 
        if (isset($data["token"])) {
            $this->_jsonBuffer.=$data["token"];
            $GLOBALS["SYNTH_LLM_RAW_TEXT"] = $this->_jsonBuffer;
            unset($GLOBALS["_JSON_BUFFER"]);
            $partialResult=__jpd_decode_lazy($this->_jsonBuffer);
            
            if (isset($partialResult["message"]))
                $partialResult[0]=$partialResult;   // __jpd_decode_lazy changes struct when JSON is completed
            
            
            if (is_array($partialResult)&&isset($partialResult[0]["message"])) {
                if (isset($partialResult[0]["action"])) {
                    if (($partialResult[0]["action"]=="Inspect")) {
                        return "";
                    }
                }
                // workaround for some LLMs that return an array of strings for the dialogue in the JSON response.
                if (is_array($partialResult[0]["message"])) {
                    $mangledBuffer = str_replace($this->_extractedbuffer, "", implode(" ",$partialResult[0]["message"]));
                } else {
                    $mangledBuffer = str_replace($this->_extractedbuffer, "", $partialResult[0]["message"]);
                }
                // echo "*{$this->_jsonBuffer}".PHP_EOL;
                $this->_extractedbuffer=$partialResult[0]["message"];
                if (isset($partialResult[0]["listener"])) {
                    if (isset($partialResult[0]["action"])&&($partialResult[0]["action"]=="Talk")&& lazyEmpty($partialResult[0]["listener"]) && !lazyEmpty($partialResult[0]["target"]))
                        $GLOBALS["SCRIPTLINE_LISTENER"]=$partialResult[0]["target"];
                    else
                        $GLOBALS["SCRIPTLINE_LISTENER"]=$partialResult[0]["listener"];

                     
                }
                
                if (isset($partialResult[0]["mood"])) {
                    $partialResult[0]["mood"] = extractFirstEmoteMood($partialResult[0]["mood"]);
                    $GLOBALS["SCRIPTLINE_ANIMATION"]=GetAnimationHex($partialResult[0]["mood"]);
                    $GLOBALS["SCRIPTLINE_EXPRESSION"]=GetExpression($partialResult[0]["mood"]);
                }
                
                // Store the entire response for TTS systems that need additional data like emotions
                $GLOBALS["LAST_LLM_RESPONSE"] = $partialResult[0];
                
            }
            
            $totalBuffer.=$data["token"];
        }

        
        return $mangledBuffer;
    }


    public function close()
    {
        // Closing must not drain an unfinished provider response.
        synth_provider_close($this->primary_handler);
        $this->primary_handler = null;
        $GLOBALS["DEBUG_DATA"]["RAW"]=$this->_jsonBuffer;

        //file_put_contents(__DIR__."/../log/output_from_llm.log",$this->_jsonBuffer, FILE_APPEND | LOCK_EX);
        file_put_contents(__DIR__."/../log/output_from_llm.log",date(DATE_ATOM)."\n=\n".$this->_jsonBuffer."\n=\n", FILE_APPEND);
        return $this->_jsonBuffer;


    }

    public function isDone()
    {
        if ($this->_ignoreRest)
            return true;

        return !$this->primary_handler || synth_provider_eof($this->primary_handler);
    }

    public function processActions()
    {
        global $alreadysent;

        unset($GLOBALS["_JSON_BUFFER"]);    // __jpd_decode_lazy has a cache
        
        $jsonData=__jpd_decode_lazy($this->_jsonBuffer);
        
        //error_log($this->_jsonBuffer);
        //print_r($jsonData);
        
        if (is_array($jsonData)) {  // ??
            if (isset($jsonData[0])&& is_array($jsonData[0]))
                $jsonData=$jsonData[0];
        }
        
        $this->_commandBuffer=[];

        $GLOBALS["DEBUG_DATA"]["RAW"]=$this->_jsonBuffer;
        
        if (is_array($jsonData)&&isset($jsonData["action"])) { // !!!
            $parsedResponse=$jsonData;
            
            if (!empty($parsedResponse["action"])) {
                $executionContext = buildFunctionExecutionContextFromResponse($parsedResponse);
                queueFunctionExecutionCommand($this->_commandBuffer, $alreadysent, $executionContext, "koboldcppjson");
            }
        }
        
        //print_r($this->_jsonBuffer);
        return is_array($this->_commandBuffer)?$this->_commandBuffer:[];
        
    }


}

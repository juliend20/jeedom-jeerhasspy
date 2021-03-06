<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* * ***************************Includes********************************* */
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/rhasspy.utils.class.php';

class jeerhasspy extends eqLogic {
    public static $_widgetPossibility = array('custom' => true, 'custom::layout' => false);

    //rhasspy called endpoint forwarded by jeeAPI:
    public static function event() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $payload = json_decode(file_get_contents('php://input'), true);
            //wakeword received:
            if (isset($payload['wakewordId'])) {
                if (config::byKey('setWakeVariables', 'jeerhasspy') == '1') {
                    $payload['siteId'] = explode(',', $payload['siteId'])[0];
                    scenario::setData('rhasspyWakeWord', $payload['wakewordId']);
                    scenario::setData('rhasspyWakeSiteId', $payload['siteId']);
                    RhasspyUtils::logger('Set variables: rhasspyWakeWord | rhasspyWakeSiteId: '.$payload['wakewordId'].' | '.$payload['siteId']);
                }
                return;
            }

            $_answerToRhasspy = array('speech' => array('text' => ''));
            //intent received:
            if (isset($payload['intent']) && isset($payload['intent']['name'])) {
                $intentName = $payload['intent']['name'];
                $payload['siteId'] = explode(',', $payload['siteId'])[0];
                if ($intentName != '') {
                    //check if Ask answer:
                    $isAskAnswer = false;
                    if (isset($payload['entities'])) {
                        $askData = false;
                        foreach ($payload['entities'] as $entity) {
                            if ($entity['entity'] == 'askData') {
                                $isAskAnswer = true;
                                $askData = $entity['value'];
                                break;
                            }
                        }
                        if ($askData) {
                            $data = explode('::', $askData);
                            $answerEntity = $data[0];
                            $answerVariable = $data[1];
                            $answer = false;
                            foreach ($payload['entities'] as $entity) {
                                if ($entity['entity'] == $answerEntity) {
                                    $answer = $entity['value'];
                                    break;
                                }
                            }
                            if ($answer) {
                                RhasspyUtils::logger('Ask answer received, set answer variable: '.$answer);
                                scenario::setData($answerVariable, $answer);
                            }
                        }
                    }

                    if (!$isAskAnswer) {
                        RhasspyUtils::logger('Event received: '.json_encode($payload));
                      	$speakDefault = false;
                        $eqLogic = eqLogic::byLogicalId($intentName, 'jeerhasspy');
                        if (is_object($eqLogic) && $eqLogic->getIsEnable() == 1)
                        {
                          	$callbackScenario = $eqLogic->getConfiguration('callbackScenario');
                          	$minConfidence = 0;
                          	if (isset($callbackScenario['minConfidence'])) $minConfidence = floatval($callbackScenario['minConfidence']);
                            if ($minConfidence <= floatval($payload['intent']['confidence'])) {
                              $_exec = $eqLogic->exec_callback_scenario($payload);
                              if (!$_exec) $speakDefault = true;
                            } else {
                              $speakDefault = true;
                            }
                        } else {
                            $speakDefault = true;
                        }
                      	if ($speakDefault) $_answerToRhasspy['speech']['text'] = config::byKey('defaultTTS', 'jeerhasspy');
                    }
                }
                //always answer to rhasspy:
                header('Content-Type: application/json');
                echo json_encode($_answerToRhasspy);
                return;
            }
        }
    }

    //Get intent eq scenario:
    public function exec_callback_scenario($payload=null)
    {
        $callback_settings = $this->getConfiguration('callbackScenario');
        RhasspyUtils::logger('callback_settings: '.json_encode($callback_settings));

        if (!is_array($callback_settings) || $callback_settings['scenario'] == '-1') {
            return false;
        }

        $_scenarioId = $callback_settings['scenario'];
        $_scenarioAction = $callback_settings['action'];

        if (!is_object(scenario::byId($_scenarioId))) {
            RhasspyUtils::logger('scenario: id '. $_scenarioId .' does not exist', 'error');
            return false;
        }
        $options = array();
        $options['scenario_id'] = $_scenarioId;
        $options['action'] = $_scenarioAction;
        $options['tags'] = $this->get_all_scenario_tags($callback_settings, $payload);

        return scenarioExpression::createAndExec('action', 'scenario', $options);
    }
    //Set scenario tags for scenario exec:
    public function get_all_scenario_tags($_options, $_payload)
    {
        RhasspyUtils::logger('__options: '.json_encode($_payload));
        $tags = array();
        $userTags = $_options['user_tags'];

        $userTags = arg2array($_options['user_tags']);
        foreach ($userTags as $key => $value) {
            $tags['#' . trim(trim($key), '#') . '#'] = $value;
        }

        if ($_options['isTagIntent'] == '1') $tags['#intent#'] = $_payload['intent']['name'];
        if ($_options['isTagConfidence'] == '1') $tags['#confidence#'] = $_payload['intent']['confidence'];
        if ($_options['isTagWakeword'] == '1') $tags['#wakeword#'] = $_payload['wakeId'];
        if ($_options['isTagQuery'] == '1') $tags['#query#'] = $_payload['text'];
        if ($_options['isTagSiteId'] == '1') $tags['#siteId#'] = $_payload['siteId'];

        if ($_options['isTagEntities'] == '1') {
            foreach ($_payload['entities'] as $entity) {
                $tags['#'.$entity['entity'].'#'] = $entity['value'];
            }
        }
        if ($_options['isTagSlots'] == '1') {
            foreach ($_payload['slots'] as $slot => $value) {
                $tags['#'.$slot.'#'] = $value;
            }
        }

        RhasspyUtils::logger('__return tags: '.json_encode($tags));
        return $tags;
    }

    /*     * *********************Méthodes d'instance************************* */
    public function preInsert() {}
    public function postInsert() {}
    public function preSave() {}
    public function postSave() {}
    public function preUpdate() {}
    public function postUpdate() {}
    public function preRemove() {}
    public function postRemove() {}

    /*     * **********************Getteur Setteur*************************** */
}

class jeerhasspyCmd extends cmd {

    public function execute($options = array())
    {
        $eqlogic = $this->getEqLogic();
        RhasspyUtils::logger($eqlogic->getName());
        switch ($this->getLogicalId()) {
            case 'speak':
                $this->rhasspy_speak($options);
                break;
            case 'dynspeak':
                $this->rhasspy_dynamicSpeak($options);
                break;
            case 'ask':
                $this->rhasspy_ask($options);
                break;
        }
    }

    public function rhasspy_speak($options = array())
    {
        $eqName = $this->getEqLogic()->getName();
        $siteId = str_replace('TTS-', '', $eqName);
        $options['title'] = $siteId;
        RhasspyUtils::logger($options);
        RhasspyUtils::textToSpeech($options);
    }

    public function rhasspy_dynamicSpeak($options = array())
    {
        $eqName = $this->getEqLogic()->getName();
        $siteId = str_replace('TTS-', '', $eqName);
        $options['title'] = $siteId;
        RhasspyUtils::logger($options['message']);
        $options['message'] = RhasspyUtils::evalDynamicString($options['message']);
        RhasspyUtils::textToSpeech($options);
    }

    public function rhasspy_ask($options = array())
    {
        $eqName = $this->getEqLogic()->getName();
        $siteId = str_replace('TTS-', '', $eqName);
        RhasspyUtils::logger(json_encode($options).' siteId: '.$siteId);

        $answer_entity = $options['answer'][0];
        $answer_variable = $options['variable'];
        $options['title'] = $siteId;
        RhasspyUtils::textToSpeech($options);

        $options['askData'] = $answer_entity.'::'.$answer_variable;
        RhasspyUtils::speakToAsk($options, $siteId);
    }
}
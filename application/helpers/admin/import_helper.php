<?php
/*
* LimeSurvey
* Copyright (C) 2007-2011 The LimeSurvey Project Team / Carsten Schmitz
* All rights reserved.
* License: GNU/GPL License v2 or later, see LICENSE.php
* LimeSurvey is free software. This version may have been modified pursuant
* to the GNU General Public License, and as distributed it includes or
* is derivative of works licensed under the GNU General Public License or
* other free or open source software licenses.
* See COPYRIGHT.php for copyright notices and details.
*/

/**
* This function imports a LimeSurvey .lsg question group XML file
*
* @param string $sFullFilePath  The full filepath of the uploaded file
* @param integer $iNewSID The new survey id - the group will always be added after the last group in the survey
*/
function XMLImportGroup($sFullFilePath, $iNewSID)
{
    $sBaseLanguage         = Survey::model()->findByPk($iNewSID)->language;
    $bOldEntityLoaderState = libxml_disable_entity_loader(true); // @see: http://phpsecurity.readthedocs.io/en/latest/Injection-Attacks.html#xml-external-entity-injection

    $sXMLdata              = file_get_contents($sFullFilePath);
    $xml                   = simplexml_load_string($sXMLdata, 'SimpleXMLElement', LIBXML_NONET);


    if ($xml === false || $xml->LimeSurveyDocType != 'Group') {
        safeDie('This is not a valid LimeSurvey group structure XML file.');
    }

    $iDBVersion = (int) $xml->DBVersion;
    $aQIDReplacements = array();
    $results['defaultvalues'] = 0;
    $results['answers'] = 0;
    $results['question_attributes'] = 0;
    $results['subquestions'] = 0;
    $results['conditions'] = 0;
    $results['groups'] = 0;

    $importlanguages = array();
    foreach ($xml->languages->language as $language) {
        $importlanguages[] = (string) $language;
    }

    if (!in_array($sBaseLanguage, $importlanguages)) {
        $results['fatalerror'] = gT("The languages of the imported group file must at least include the base language of this survey.");
        return $results;
    }
    // First get an overview of fieldnames - it's not useful for the moment but might be with newer versions
    /*
    $fieldnames=array();
    foreach ($xml->questions->fields->fieldname as $fieldname ){
    $fieldnames[]=(string)$fieldname;
    };*/


    // Import group table ===================================================================================
    $iGroupOrder = Yii::app()->db->createCommand()->select('MAX(group_order)')->from('{{groups}}')->where('sid=:sid', array(':sid'=>$iNewSID))->queryScalar();
    if ($iGroupOrder === false) {
        $iNewGroupOrder = 0;
    } else {
        $iNewGroupOrder = $iGroupOrder + 1;
    }

    foreach ($xml->groups->rows->row as $row) {
        $insertdata = array();
        foreach ($row as $key=>$value) {
            $insertdata[(string) $key] = (string) $value;
        }
        $iOldSID = $insertdata['sid'];
        $insertdata['sid'] = $iNewSID;
        $insertdata['group_order'] = $iNewGroupOrder;
        $oldgid = $insertdata['gid']; unset($insertdata['gid']); // save the old qid
        $aDataL10n = array();

        if ($iDBVersion < 350) {
            $aDataL10n['group_name'] = $insertdata['group_name'];
            $aDataL10n['description'] = $insertdata['description'];
            $aDataL10n['language'] = $insertdata['language'];
            unset($insertdata['group_name']);
            unset($insertdata['description']);
            unset($insertdata['language']);
        }
        if (!isset($aGIDReplacements[$oldgid])) {
            $newgid = QuestionGroup::model()->insertRecords($insertdata) or safeDie(gT("Error").": Failed to insert data [3]<br />");
            $aGIDReplacements[$oldgid] = $newgid; // add old and new qid to the mapping array
            $results['groups']++;
        }
        if (!empty($aDataL10n)) {
            $aDataL10n['gid'] = $aGIDReplacements[$oldgid];
            $oQuestionGroupL10n = new QuestionGroupL10n(); 
            $oQuestionGroupL10n->setAttributes($aDataL10n, false);
            $oQuestionGroupL10n->save();
        }        
    }

    if ($iDBVersion >= 350 && isset($xml->group_l10ns->rows->row)) {
        foreach ($xml->group_l10ns->rows->row as $row) {
            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            unset($insertdata['id']);
            // now translate any links
            $insertdata['group_name'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['group_name']);
            $insertdata['description'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['description']);
            if (isset($aGIDReplacements[$insertdata['gid']])) {
                $insertdata['gid'] = $aGIDReplacements[$insertdata['gid']];
            } else {
                continue; //Skip invalid group ID
            }
            $oQuestionGroupL10n = new QuestionGroupL10n(); 
            $oQuestionGroupL10n->setAttributes($insertdata, false);
            $oQuestionGroupL10n->save();
        }    
    }
    
    // Import questions table ===================================================================================

    // We have to run the question table data two times - first to find all main questions
    // then for subquestions (because we need to determine the new qids for the main questions first)


    $results['questions'] = 0;
    if (isset($xml->questions)) {
        foreach ($xml->questions->rows->row as $row) {
            
            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            if (!isset($aGIDReplacements[$insertdata['gid']])) {
                // Skip questions with invalid group id
                continue;
            } 
            if (!isset($insertdata['mandatory']) || trim($insertdata['mandatory']) == '') {
                $insertdata['mandatory'] = 'N';
            }
            $iOldSID = $insertdata['sid'];
            $insertdata['sid'] = $iNewSID;
            $insertdata['gid'] = $aGIDReplacements[$insertdata['gid']];
            $oldqid = $insertdata['qid']; // save the old qid
            unset($insertdata['qid']); 

            if ($insertdata) {
                XSSFilterArray($insertdata);
            }            
            // now translate any links
            if ($iDBVersion < 350) {
                if ($bTranslateInsertansTags) {
                    $insertdata['question'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['question']);
                    $insertdata['help'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['help']);
                }
                $oQuestionL10n = new QuestionL10n();
                $oQuestionL10n->question = $insertdata['question'];
                $oQuestionL10n->help = $insertdata['help'];
                $oQuestionL10n->language = $insertdata['language'];
                unset($insertdata['question']);
                unset($insertdata['help']);
                unset($insertdata['language']);
            }
            $oQuestion = new Question('import');
            $oQuestion->setAttributes($insertdata, false);

            if (!isset($aQIDReplacements[$iOldQID])) {
            // Try to fix question title for valid question code enforcement
                if (!$oQuestion->validate(array('title'))) {
                    $sOldTitle = $oQuestion->title;
                    $sNewTitle = preg_replace("/[^A-Za-z0-9]/", '', $sOldTitle);
                    if (is_numeric(substr($sNewTitle, 0, 1))) {
                        $sNewTitle = 'q'.$sNewTitle;
                    }

                    $oQuestion->title = $sNewTitle;
                }

                $attempts = 0;
                // Try to fix question title for unique question code enforcement
                $index = 0;
                $rand = mt_rand(0, 1024);
                while (!$oQuestion->validate(array('title'))) {
                    $sNewTitle = 'r'.$rand.'q'.$index;
                    $index++;
                    $oQuestion->title = $sNewTitle;
                    $attempts++;
                    if ($attempts > 10) {
                        safeDie(gT("Error").": Failed to resolve question code problems after 10 attempts.<br />");
                    }
                }
                if (!$oQuestion->save()) {
                    safeDie(gT("Error while saving: ").print_r($oQuestion->errors, true));
                }
                $aQIDReplacements[$iOldQID] = $oQuestion->qid; ;
                $results['questions']++;
            } 
            
            if (isset($oQuestionL10n)) {
                $oQuestionL10n->qid = $aQIDReplacements[$iOldQID];
                $oQuestionL10n->save();
                unset($oQuestionL10n);
            }
            // Set a warning if question title was updated
            if (isset($sNewTitle) && isset($sOldTitle)) {
                $results['importwarnings'][] = sprintf(gT("Question code %s was updated to %s."), $sOldTitle, $sNewTitle);
                $aQuestionCodeReplacements[$sOldTitle] = $sNewTitle;
                unset($sNewTitle);
                unset($sOldTitle);
            }            
        }
    }

    // Import subquestions -------------------------------------------------------
    if (isset($xml->subquestions)) {

        foreach ($xml->subquestions->rows->row as $row) {
            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }

            if ($insertdata['gid'] == 0) {
                    continue;
            }            
            if (!isset($insertdata['mandatory']) || trim($insertdata['mandatory']) == '') {
                $insertdata['mandatory'] = 'N';
            }
            $iOldSID = $insertdata['sid'];
            $insertdata['sid'] = $iNewSID;
            $insertdata['gid'] = $aGIDReplacements[(int) $insertdata['gid']];
            $iOldQID = (int) $insertdata['qid']; unset($insertdata['qid']); // save the old qid
            $insertdata['parent_qid'] = $aQIDReplacements[(int) $insertdata['parent_qid']]; // remap the parent_qid
            if (!isset($insertdata['help'])) {
                $insertdata['help'] = '';
            }            // now translate any links
            if ($iDBVersion < 350) {
                if ($bTranslateInsertansTags) {
                    $insertdata['question'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['question']);
                    $insertdata['help'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['help']);
                }
                $oQuestionL10n = new QuestionL10n();
                $oQuestionL10n->question = $insertdata['question'];
                $oQuestionL10n->help = $insertdata['help'];
                $oQuestionL10n->language = $insertdata['language'];
                unset($insertdata['question']);
                unset($insertdata['help']);
                unset($insertdata['language']);
            }
            if (!$bConvertInvalidQuestionCodes) {
                $sScenario = 'archiveimport';
            } else {
                $sScenario = 'import';
            }

            $oQuestion = new Question($sScenario);
            $oQuestion->setAttributes($insertdata, false);

            if (!isset($aQIDReplacements[$iOldQID])) {
                // Try to fix question title for valid question code enforcement
                if (!$oQuestion->validate(array('title'))) {
                    $sOldTitle = $oQuestion->title;
                    $sNewTitle = preg_replace("/[^A-Za-z0-9]/", '', $sOldTitle);
                    if (is_numeric(substr($sNewTitle, 0, 1))) {
                        $sNewTitle = 'sq'.$sNewTitle;
                    }

                    $oQuestion->title = $sNewTitle;
                }

                $attempts = 0;
                // Try to fix question title for unique question code enforcement
                while (!$oQuestion->validate(array('title'))) {

                    if (!isset($index)) {
                        $index = 0;
                        $rand = mt_rand(0, 1024);
                    } else {
                        $index++;
                    }

                    $sNewTitle = 'r'.$rand.'sq'.$index;
                    $oQuestion->title = $sNewTitle;
                    $attempts++;

                    if ($attempts > 10) {
                        safeDie(gT("Error").": Failed to resolve question code problems after 10 attempts.<br />");
                    }
                }
                if (!$oQuestion->save()) {
                    safeDie(gT("Error while saving: ").print_r($oQuestion->errors, true));
                }
                $aQIDReplacements[$iOldQID] = $oQuestion->qid; ;
                $results['questions']++;
            } 

            if (isset($oQuestionL10n)) {
                $oQuestionL10n->qid = $aQIDReplacements[$iOldQID];
                $oQuestionL10n->save();
                unset($oQuestionL10n);
            }

            // Set a warning if question title was updated
            if (isset($sNewTitle) && isset($sOldTitle)) {
                $results['importwarnings'][] = sprintf(gT("Title of subquestion %s was updated to %s."), $sOldTitle, $sNewTitle); // Maybe add the question title ?
                $aQuestionCodeReplacements[$sOldTitle] = $sNewTitle;
                unset($sNewTitle);
                unset($sOldTitle);
            }
        }
    }

    
    //  Import question_l10ns
    if ($iDBVersion >= 350 && isset($xml->question_l10ns->rows->row)) {
        foreach ($xml->question_l10ns->rows->row as $row) {
            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            unset($insertdata['id']);
            // now translate any links
            $insertdata['question'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['question']);
            $insertdata['help'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['help']);
            if (isset($aQIDReplacements[$insertdata['qid']])) {
                $insertdata['qid'] = $aQIDReplacements[$insertdata['qid']];
            } else {
                continue; //Skip invalid group ID
            }
            $oQuestionL10n = new QuestionL10n(); 
            $oQuestionL10n->setAttributes($insertdata, false);
            $oQuestionL10n->save();
        }    
    }    

    // Import answers ------------------------------------------------------------
    if (isset($xml->answers)) {

        foreach ($xml->answers->rows->row as $row) {
            $insertdata = array();  

            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            if ($iDBVersion >= 350) {
                $iOldAID = $insertdata['aid'];
                unset($insertdata['aid']);
            }
            if (!isset($aQIDReplacements[(int) $insertdata['qid']])) {
                continue;
            }

            $insertdata['qid'] = $aQIDReplacements[(int) $insertdata['qid']]; // remap the parent_qid
            
            if ($iDBVersion < 350) {
                $oAnswerL10n = new AnswerL10n();
                $oAnswerL10n->answer = $insertdata['answer'];
                $oAnswerL10n->language = $insertdata['language'];
                unset($insertdata['answer']);
                unset($insertdata['language']);
            }
            
            $oAnswer = new Answer();
            $oAnswer->setAttributes($insertdata, false);
            if ($oAnswer->save() && $iDBVersion >= 350) {
                $aAIDReplacements[$iOldAID] = $oAnswer->aid;
            }
            $results['answers']++;
            if (isset($oAnswerL10n)) {
                $oAnswer = Answer::model()->findByAttributes(['qid'=>$insertdata['qid'], 'code'=>$insertdata['code'], 'scale_id'=>$insertdata['scale_id']]);                
                $oAnswerL10n->aid = $oAnswer->aid;
                $oAnswerL10n->save();
                unset($oAnswerL10n);
            }
        }
    }

    //  Import answer_l10ns
    if ($iDBVersion >= 350 && isset($xml->answer_l10ns->rows->row)) {
        foreach ($xml->answer_l10ns->rows->row as $row) {
            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            unset($insertdata['id']);
            // now translate any links
            if (isset($aAIDReplacements[$insertdata['aid']])) {
                $insertdata['aid'] = $aAIDReplacements[$insertdata['aid']];
            } else {
                continue; //Skip invalid answer ID
            }
            $oAnswerL10n = new AnswerL10n(); 
            $oAnswerL10n->setAttributes($insertdata, false);
            $oAnswerL10n->save();
        }    
    }    


    // Import questionattributes --------------------------------------------------------------
    if (isset($xml->question_attributes)) {


        $aAllAttributes = questionHelper::getAttributesDefinitions();

        foreach ($xml->question_attributes->rows->row as $row) {
            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            unset($insertdata['qaid']);
            if (!isset($aQIDReplacements[(int) $insertdata['qid']])) {
// Skip questions with invalid group id
                continue;
            } 
            $insertdata['qid'] = $aQIDReplacements[(int) $insertdata['qid']]; // remap the parent_qid


            if ($iDBVersion < 156 && isset($aAllAttributes[$insertdata['attribute']]['i18n']) && $aAllAttributes[$insertdata['attribute']]['i18n']) {
                foreach ($importlanguages as $sLanguage) {
                    $insertdata['language'] = $sLanguage;
                    Yii::app()->db->createCommand()->insert('{{question_attributes}}', $insertdata);
                }
            } else {
                Yii::app()->db->createCommand()->insert('{{question_attributes}}', $insertdata);
            }
            $results['question_attributes']++;
        }
    }


    // Import defaultvalues --------------------------------------------------------------
    if (isset($xml->defaultvalues)) {


        $results['defaultvalues'] = 0;
        foreach ($xml->defaultvalues->rows->row as $row) {
            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            $insertdata['qid'] = $aQIDReplacements[(int) $insertdata['qid']]; // remap the qid
            if ($insertdata['sqid'] > 0) {
                if (!isset($aQIDReplacements[(int) $insertdata['sqid']])) {
// Skip questions with invalid subquestion id
                    continue;
                } 
                $insertdata['sqid'] = $aQIDReplacements[(int) $insertdata['sqid']]; // remap the subquestion id
            }

            // now translate any links
            Yii::app()->db->createCommand()->insert('{{defaultvalues}}', $insertdata);
            $results['defaultvalues']++;
        }
    }

    // Import conditions --------------------------------------------------------------
    if (isset($xml->conditions)) {


        foreach ($xml->conditions->rows->row as $row) {
            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            // replace the qid for the new one (if there is no new qid in the $aQIDReplacements array it mean that this condition is orphan -> error, skip this record)
            if (isset($aQIDReplacements[$insertdata['qid']])) {
                $insertdata['qid'] = $aQIDReplacements[$insertdata['qid']]; // remap the qid
            } else {
// a problem with this answer record -> don't consider
                continue;
            } 
            if (isset($aQIDReplacements[$insertdata['cqid']])) {
                $insertdata['cqid'] = $aQIDReplacements[$insertdata['cqid']]; // remap the qid
            } else {
// a problem with this answer record -> don't consider
                continue;
            } 

            list($oldcsid, $oldcgid, $oldqidanscode) = explode("X", $insertdata["cfieldname"], 3);

            if ($oldcgid != $oldgid) {
                // this means that the condition is in another group (so it should not have to be been exported -> skip it
                continue;
            }

            unset($insertdata["cid"]);

            // recreate the cfieldname with the new IDs
            if (preg_match("/^\+/", $oldcsid)) {
                $newcfieldname = '+'.$iNewSID."X".$newgid."X".$insertdata["cqid"].substr($oldqidanscode, strlen($oldqid));
            } else {
                $newcfieldname = $iNewSID."X".$newgid."X".$insertdata["cqid"].substr($oldqidanscode, strlen($oldqid));
            }

            $insertdata["cfieldname"] = $newcfieldname;
            if (trim($insertdata["method"]) == '') {
                $insertdata["method"] = '==';
            }

            // now translate any links
            Yii::app()->db->createCommand()->insert('{{conditions}}', $insertdata);
            $results['conditions']++;
        }
    }
    LimeExpressionManager::RevertUpgradeConditionsToRelevance($iNewSID);
    LimeExpressionManager::UpgradeConditionsToRelevance($iNewSID);

    $results['newgid'] = $newgid;
    $results['labelsets'] = 0;
    $results['labels'] = 0;

    libxml_disable_entity_loader($bOldEntityLoaderState); // Put back entity loader to its original state, to avoid contagion to other applications on the server
    return $results;
}

/**
* This function imports a LimeSurvey .lsq question XML file
*
* @param string $sFullFilePath  The full filepath of the uploaded file
* @param integer $iNewSID The new survey id
* @param mixed $newgid The new question group id -the question will always be added after the last question in the group
*/
function XMLImportQuestion($sFullFilePath, $iNewSID, $newgid, $options = array('autorename'=>false))
{
    $sBaseLanguage = Survey::model()->findByPk($iNewSID)->language;
    $sXMLdata = file_get_contents($sFullFilePath);
    $xml = simplexml_load_string($sXMLdata, 'SimpleXMLElement', LIBXML_NONET);
    if ($xml->LimeSurveyDocType != 'Question') {
        safeDie('This is not a valid LimeSurvey question structure XML file.');
    }
    $iDBVersion = (int) $xml->DBVersion;
    $aQIDReplacements = array();
    $aSQIDReplacements = array(0=>0);

    $results['defaultvalues'] = 0;
    $results['answers'] = 0;
    $results['question_attributes'] = 0;
    $results['subquestions'] = 0;

    $importlanguages = array();
    foreach ($xml->languages->language as $language) {
        $importlanguages[] = (string) $language;
    }

    if (!in_array($sBaseLanguage, $importlanguages)) {
        $results['fatalerror'] = gT("The languages of the imported question file must at least include the base language of this survey.");
        return $results;
    }
    // First get an overview of fieldnames - it's not useful for the moment but might be with newer versions
    /*
    $fieldnames=array();
    foreach ($xml->questions->fields->fieldname as $fieldname ){
    $fieldnames[]=(string)$fieldname;
    };*/


    // Import questions table ===================================================================================

    // We have to run the question table data two times - first to find all main questions
    // then for subquestions (because we need to determine the new qids for the main questions first)


    $query = "SELECT MAX(question_order) AS maxqo FROM {{questions}} WHERE sid=$iNewSID AND gid=$newgid";
    $res = Yii::app()->db->createCommand($query)->query();
    $resrow = $res->read();
    $newquestionorder = $resrow['maxqo'] + 1;
    if (is_null($newquestionorder)) {
        $newquestionorder = 0;
    } else {
        $newquestionorder++;
    }
    foreach ($xml->questions->rows->row as $row) {
        
        $insertdata = array();
        foreach ($row as $key=>$value) {
            $insertdata[(string) $key] = (string) $value;
        }

        $iOldSID = $insertdata['sid'];
        $insertdata['sid'] = $iNewSID;
        $insertdata['gid'] = $newgid;
        $insertdata['question_order'] = $newquestionorder;
        $iOldQID = $insertdata['qid']; // save the old qid
        unset($insertdata['qid']); 

        // now translate any links
        if ($iDBVersion < 350) {
            $insertdata['question'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['question']);
            $insertdata['help'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['help']);
            $oQuestionL10n = new QuestionL10n();
            $oQuestionL10n->question = $insertdata['question'];
            $oQuestionL10n->help = $insertdata['help'];
            $oQuestionL10n->language = $insertdata['language'];
            unset($insertdata['question']);
            unset($insertdata['help']);
            unset($insertdata['language']);            
        }
            
        $oQuestion = new Question('import');
        $oQuestion->setAttributes($insertdata, false);

        if (!isset($aQIDReplacements[$iOldQID])) {
            if (!$oQuestion->validate(array('title')) && $options['autorename']) {
                if (isset($sNewTitle)) {
                    $oQuestion->title = $sNewTitle;
                } else {
                    $sOldTitle = $oQuestion->title;
                    $oQuestion->title = $sNewTitle = $oQuestion->getNewTitle();
                    if (!$sNewTitle) {
                        $results['fatalerror'] = CHtml::errorSummary($oQuestion, gT("The question could not be imported for the following reasons:"));
                        return $results;
                    }
                    $results['importwarnings'][] = sprintf(gT("Question code %s was updated to %s."), $sOldTitle, $sNewTitle);
                    unset($sNewTitle);
                    unset($sOldTitle);
                }
            }
            if (!$oQuestion->save()) {
                $results['fatalerror'] = CHtml::errorSummary($oQuestion, gT("The question could not be imported for the following reasons:"));
                return $results;
            }
            $aQIDReplacements[$iOldQID] = $oQuestion->qid; ;
            $results['questions']++;
        } 
        if (isset($oQuestionL10n)) {
            $oQuestionL10n->qid = $aQIDReplacements[$iOldQID];
            $oQuestionL10n->save();
            unset($oQuestionL10n);
        }
    }

    // Import subquestions -------------------------------------------------------
    if (isset($xml->subquestions)) {

        foreach ($xml->subquestions->rows->row as $row) {
            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }

            if ($iDBVersion < 350) {
                if (!in_array($insertdata['language'], $aLanguagesSupported)) {
                    continue;
                }
            }
            if ($insertdata['gid'] == 0) {
                    continue;
            }            
            if (!isset($insertdata['mandatory']) || trim($insertdata['mandatory']) == '') {
                $insertdata['mandatory'] = 'N';
            }
            $iOldSID = $insertdata['sid'];
            $insertdata['sid'] = $iNewSID;
            $insertdata['gid'] = $aGIDReplacements[(int) $insertdata['gid']];
            $iOldQID = (int) $insertdata['qid']; unset($insertdata['qid']); // save the old qid
            $insertdata['parent_qid'] = $aQIDReplacements[(int) $insertdata['parent_qid']]; // remap the parent_qid
            if ($insertdata) {
                XSSFilterArray($insertdata);
            }
            if (!isset($insertdata['help'])) {
                $insertdata['help'] = '';
            }            // now translate any links
            if ($iDBVersion < 350) {
                if ($bTranslateInsertansTags) {
                    $insertdata['question'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['question']);
                    $insertdata['help'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['help']);
                }
                $oQuestionL10n = new QuestionL10n();
                $oQuestionL10n->question = $insertdata['question'];
                $oQuestionL10n->help = $insertdata['help'];
                $oQuestionL10n->language = $insertdata['language'];
                unset($insertdata['question']);
                unset($insertdata['help']);
                unset($insertdata['language']);
            }
            if (!$bConvertInvalidQuestionCodes) {
                $sScenario = 'archiveimport';
            } else {
                $sScenario = 'import';
            }

            $oQuestion = new Question($sScenario);
            $oQuestion->setAttributes($insertdata, false);

            if (!isset($aQIDReplacements[$iOldQID])) {
                // Try to fix question title for valid question code enforcement
                if (!$oQuestion->validate(array('title'))) {
                    $sOldTitle = $oQuestion->title;
                    $sNewTitle = preg_replace("/[^A-Za-z0-9]/", '', $sOldTitle);
                    if (is_numeric(substr($sNewTitle, 0, 1))) {
                        $sNewTitle = 'sq'.$sNewTitle;
                    }

                    $oQuestion->title = $sNewTitle;
                }

                $attempts = 0;
                // Try to fix question title for unique question code enforcement
                while (!$oQuestion->validate(array('title'))) {

                    if (!isset($index)) {
                        $index = 0;
                        $rand = mt_rand(0, 1024);
                    } else {
                        $index++;
                    }

                    $sNewTitle = 'r'.$rand.'sq'.$index;
                    $oQuestion->title = $sNewTitle;
                    $attempts++;

                    if ($attempts > 10) {
                        safeDie(gT("Error").": Failed to resolve question code problems after 10 attempts.<br />");
                    }
                }
                if (!$oQuestion->save()) {
                    safeDie(gT("Error while saving: ").print_r($oQuestion->errors, true));
                }
                $aQIDReplacements[$iOldQID] = $oQuestion->qid; ;
                $results['questions']++;
            } 

            if (isset($oQuestionL10n)) {
                $oQuestionL10n->qid = $aQIDReplacements[$iOldQID];
                $oQuestionL10n->save();
                unset($oQuestionL10n);
            }

            // Set a warning if question title was updated
            if (isset($sNewTitle) && isset($sOldTitle)) {
                $results['importwarnings'][] = sprintf(gT("Title of subquestion %s was updated to %s."), $sOldTitle, $sNewTitle); // Maybe add the question title ?
                $aQuestionCodeReplacements[$sOldTitle] = $sNewTitle;
                unset($sNewTitle);
                unset($sOldTitle);
            }
        }
    }
    
    //  Import question_l10ns
    if ($iDBVersion >= 350 && isset($xml->question_l10ns->rows->row)) {
        foreach ($xml->question_l10ns->rows->row as $row) {
            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            unset($insertdata['id']);
            // now translate any links
            $insertdata['question'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['question']);
            $insertdata['help'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['help']);
            if (isset($aQIDReplacements[$insertdata['qid']])) {
                $insertdata['qid'] = $aQIDReplacements[$insertdata['qid']];
            } else {
                continue; //Skip invalid group ID
            }
            $oQuestionL10n = new QuestionL10n(); 
            $oQuestionL10n->setAttributes($insertdata, false);
            $oQuestionL10n->save();
        }    
    }

    // Import answers ------------------------------------------------------------
    if (isset($xml->answers)) {

        foreach ($xml->answers->rows->row as $row) {
            $insertdata = array();  

            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            if ($iDBVersion >= 350) {
                $iOldAID = $insertdata['aid'];
                unset($insertdata['aid']);
            }
            if (!isset($aQIDReplacements[(int) $insertdata['qid']])) {
                continue;
            }

            $insertdata['qid'] = $aQIDReplacements[(int) $insertdata['qid']]; // remap the parent_qid
            
            if ($iDBVersion < 350) {
                // now translate any links
                if (!in_array($insertdata['language'], $aLanguagesSupported)) {
                    continue;
                }                 
                if ($bTranslateInsertansTags) {
                    $insertdata['answer'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['answer']);
                }
                $oAnswerL10n = new AnswerL10n();
                $oAnswerL10n->answer = $insertdata['answer'];
                $oAnswerL10n->language = $insertdata['language'];
                unset($insertdata['answer']);
                unset($insertdata['language']);
            }
            
            $oAnswer = new Answer();
            $oAnswer->setAttributes($insertdata, false);
            if ($oAnswer->save() && $iDBVersion >= 350) {
                $aAIDReplacements[$iOldAID] = $oAnswer->aid;
            }
            $results['answers']++;
            if (isset($oAnswerL10n)) {
                $oAnswer = Answer::model()->findByAttributes(['qid'=>$insertdata['qid'], 'code'=>$insertdata['code'], 'scale_id'=>$insertdata['scale_id']]);                
                $oAnswerL10n->aid = $oAnswer->aid;
                $oAnswerL10n->save();
                unset($oAnswerL10n);
            }
        }
    }

    //  Import answer_l10ns
    if ($iDBVersion >= 350 && isset($xml->answer_l10ns->rows->row)) {
        foreach ($xml->answer_l10ns->rows->row as $row) {
            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            unset($insertdata['id']);
            // now translate any links
            if ($bTranslateInsertansTags) {
                $insertdata['answer'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['answer']);
            }
            if (isset($aAIDReplacements[$insertdata['aid']])) {
                $insertdata['aid'] = $aAIDReplacements[$insertdata['aid']];
            } else {
                continue; //Skip invalid answer ID
            }
            $oAnswerL10n = new AnswerL10n(); 
            $oAnswerL10n->setAttributes($insertdata, false);
            $oAnswerL10n->save();
        }    
    }    

    // Import questionattributes --------------------------------------------------------------
    if (isset($xml->question_attributes)) {


        $aAllAttributes = questionHelper::getAttributesDefinitions();
        foreach ($xml->question_attributes->rows->row as $row) {
            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            unset($insertdata['qaid']);
            $insertdata['qid'] = $aQIDReplacements[(integer) $insertdata['qid']]; // remap the parent_qid


            if ($iDBVersion < 156 && isset($aAllAttributes[$insertdata['attribute']]['i18n']) && $aAllAttributes[$insertdata['attribute']]['i18n']) {
                foreach ($importlanguages as $sLanguage) {
                    $insertdata['language'] = $sLanguage;
                    $attributes = new QuestionAttribute;
                    if ($insertdata) {
                                            XSSFilterArray($insertdata);
                    }
                    foreach ($insertdata as $k => $v) {
                                            $attributes->$k = $v;
                    }

                    $attributes->save();
                }
            } else {
                $attributes = new QuestionAttribute;
                if ($insertdata) {
                                    XSSFilterArray($insertdata);
                }
                foreach ($insertdata as $k => $v) {
                                    $attributes->$k = $v;
                }

                $attributes->save();
            }
            $results['question_attributes']++;
        }
    }


    // Import defaultvalues --------------------------------------------------------------
    if (isset($xml->defaultvalues)) {

        $results['defaultvalues'] = 0;
        foreach ($xml->defaultvalues->rows->row as $row) {
            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            $insertdata['qid'] = $aQIDReplacements[(int) $insertdata['qid']]; // remap the qid
            $insertdata['sqid'] = $aSQIDReplacements[(int) $insertdata['sqid']]; // remap the subquestion id

            // now translate any links
            $default = new DefaultValue;
            if ($insertdata) {
                XSSFilterArray($insertdata);
            }

            foreach ($insertdata as $k => $v) {
                $default->$k = $v;
            }

            $default->save();
            $results['defaultvalues']++;
        }
    }
    
    LimeExpressionManager::SetDirtyFlag(); // so refreshes syntax highlighting

    $results['newqid'] = $newqid;
    $results['questions'] = 1;
    $results['labelsets'] = 0;
    $results['labels'] = 0;
    return $results;
}

/**
* XMLImportLabelsets()
* Function resp[onsible to import a labelset from XML format.
* @param string $sFullFilePath
* @param mixed $options
* @return
*/
function XMLImportLabelsets($sFullFilePath, $options)
{

    $sXMLdata = (string) file_get_contents($sFullFilePath);
    $xml = simplexml_load_string($sXMLdata, 'SimpleXMLElement', LIBXML_NONET);
    if ($xml->LimeSurveyDocType != 'Label set') {
        safeDie('This is not a valid LimeSurvey label set structure XML file.');
    }
    $iDBVersion = (int) $xml->DBVersion;
    $aLSIDReplacements = $results = [];
    $results['labelsets'] = 0;
    $results['labels'] = 0;
    $results['warnings'] = array();
    $aImportedLabelSetIDs = array();

    // Import label sets table ===================================================================================
    foreach ($xml->labelsets->rows->row as $row) {
        $insertdata = array();
        foreach ($row as $key=>$value) {
            $insertdata[(string) $key] = (string) $value;
        }
        $iOldLabelSetID = $insertdata['lid'];
        unset($insertdata['lid']); // save the old qid

        // Insert the new question
        $arLabelset = new LabelSet();
        $arLabelset->setAttributes($insertdata);
        $arLabelset->save();
        $aLSIDReplacements[$iOldLabelSetID] = $arLabelset->lid; // add old and new lsid to the mapping array
        $results['labelsets']++;
        $aImportedLabelSetIDs[] = $arLabelset->lid;
    }

    // Import labels table ===================================================================================
    if (isset($xml->labels->rows->row)) {
        foreach ($xml->labels->rows->row as $row) {
            $insertdata = [];
            $insertdataLS = [];
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            $insertdata['lid'] = $aLSIDReplacements[$insertdata['lid']];
            if ($iDBVersion < 350) {
                $insertdataLS['title'] = $insertdata['title']; 
                $insertdataLS['language'] = $insertdata['language']; 
                unset ($insertdata['title']);
                unset ($insertdata['language']);
            } else {
                $iOldLabelID = $insertdata['id'];
            }
            unset ($insertdata['id']);
            
            if ($iDBVersion < 350) {
                $findLabel = Label::model()->findByAttributes($insertdata);
                if (empty($findLabel)) {
                    $arLabel = new Label();
                    $arLabel->setAttributes($insertdata);
                    $arLabel->save();
                    $insertdataLS['label_id'] = $arLabel->id;
                } else {
                    $insertdataLS['label_id'] = $findLabel->id;
                }
                $arLabelL10n = new LabelL10n();
                $arLabelL10n->setAttributes($insertdataLS);
                $arLabelL10n->save();
            } else {
                $arLabel = new Label();
                $arLabel->setAttributes($insertdata);
                $arLabel->save();
                $aLIDReplacements[$iOldLabelID] = $arLabel->id;
            }
            
            $results['labels']++;
        }
    }

    // Import label_l10ns table ===================================================================================
    if (isset($xml->label_l10ns->rows->row)) {
        foreach ($xml->label_l10ns->rows->row as $row) {
            $insertdata = [];
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            $insertdata['label_id'] = $aLIDReplacements[$insertdata['label_id']];
            $arLabelL10n = new LabelL10n();
            $arLabelL10n->setAttributes($insertdata);
            $arLabelL10n->save();
            
        }
    }
    
    //CHECK FOR DUPLICATE LABELSETS

    if ($options['checkforduplicates'] == 'on') {

        $aLabelSetCheckSums = buildLabelSetCheckSumArray();
        $aCounts = array_count_values($aLabelSetCheckSums);
        foreach ($aImportedLabelSetIDs as $iLabelSetID) {
            if ($aCounts[$aLabelSetCheckSums[$iLabelSetID]] > 1) {
                LabelSet::model()->deleteLabelSet($iLabelSetID);
            }
        }

        //END CHECK FOR DUPLICATES
    }
    return $results;
}

/**
 * @param string $sFullFilePath
 * @param boolean $bTranslateLinksFields
 * @param string $sNewSurveyName
 * @param integer $DestSurveyID
 */
function importSurveyFile($sFullFilePath, $bTranslateLinksFields, $sNewSurveyName = null, $DestSurveyID = null)
{
    $aPathInfo = pathinfo($sFullFilePath);
    if (isset($aPathInfo['extension'])) {
        $sExtension = strtolower($aPathInfo['extension']);
    } else {
        $sExtension = "";
    }

    if ($sExtension == 'lss') {
        $aImportResults = XMLImportSurvey($sFullFilePath, null, $sNewSurveyName, $DestSurveyID, $bTranslateLinksFields);
        if ($aImportResults && $aImportResults['newsid']) {
            TemplateConfiguration::checkAndcreateSurveyConfig($aImportResults['newsid']);
        }
        return $aImportResults;
    } elseif ($sExtension == 'txt' || $sExtension == 'tsv') {
        $aImportResults = TSVImportSurvey($sFullFilePath);
        if ($aImportResults && $aImportResults['newsid']) {
            TemplateConfiguration::checkAndcreateSurveyConfig($aImportResults['newsid']);
        }
        return $aImportResults;
    } elseif ($sExtension == 'lsa') {
            // Import a survey archive
        Yii::import("application.libraries.admin.pclzip.pclzip", true);
        $pclzip = new PclZip(array('p_zipname' => $sFullFilePath));
        $aFiles = $pclzip->listContent();

        if ($pclzip->extract(PCLZIP_OPT_PATH, Yii::app()->getConfig('tempdir').DIRECTORY_SEPARATOR, PCLZIP_OPT_BY_EREG, '/(lss|lsr|lsi|lst)$/') == 0) {
            unset($pclzip);
        }
        $aImportResults = [];
        // Step 1 - import the LSS file and activate the survey
        foreach ($aFiles as $aFile) {

            if (pathinfo($aFile['filename'], PATHINFO_EXTENSION) == 'lss') {
                //Import the LSS file
                $aImportResults = XMLImportSurvey(Yii::app()->getConfig('tempdir').DIRECTORY_SEPARATOR.$aFile['filename'], null, null, null, true, false);
                if ($aImportResults && $aImportResults['newsid']) {
                    TemplateConfiguration::checkAndcreateSurveyConfig($aImportResults['newsid']);
                }
                // Activate the survey
                Yii::app()->loadHelper("admin/activate");
                activateSurvey($aImportResults['newsid']);
                unlink(Yii::app()->getConfig('tempdir').DIRECTORY_SEPARATOR.$aFile['filename']);
                break;
            }
        }

        // Step 2 - import the responses file
        foreach ($aFiles as $aFile) {

            if (pathinfo($aFile['filename'], PATHINFO_EXTENSION) == 'lsr') {
                //Import the LSS file
                $aResponseImportResults = XMLImportResponses(Yii::app()->getConfig('tempdir').DIRECTORY_SEPARATOR.$aFile['filename'], $aImportResults['newsid'], $aImportResults['FieldReMap']);
                $aImportResults = array_merge($aResponseImportResults, $aImportResults);
                unlink(Yii::app()->getConfig('tempdir').DIRECTORY_SEPARATOR.$aFile['filename']);
                break;
            }
        }

        // Step 3 - import the tokens file - if exists
        foreach ($aFiles as $aFile) {

            if (pathinfo($aFile['filename'], PATHINFO_EXTENSION) == 'lst') {
                Yii::app()->loadHelper("admin/token");
                $aTokenImportResults = [];
                if (Token::createTable($aImportResults['newsid'])) {
                    $aTokenCreateResults = array('tokentablecreated' => true);
                    $aImportResults = array_merge($aTokenCreateResults, $aImportResults);
                    $aTokenImportResults = XMLImportTokens(Yii::app()->getConfig('tempdir').DIRECTORY_SEPARATOR.$aFile['filename'], $aImportResults['newsid']);
                } else {
                    $aTokenImportResults['warnings'][] = gT("Unable to create survey participants table");

                }

                $aImportResults = array_merge_recursive($aTokenImportResults, $aImportResults);
                $aImportResults['importwarnings'] = array_merge($aImportResults['importwarnings'], $aImportResults['warnings']);
                unlink(Yii::app()->getConfig('tempdir').DIRECTORY_SEPARATOR.$aFile['filename']);
                break;
            }
        }
        // Step 4 - import the timings file - if exists
        Yii::app()->db->schema->refresh();
        foreach ($aFiles as $aFile) {
            if (pathinfo($aFile['filename'], PATHINFO_EXTENSION) == 'lsi' && tableExists("survey_{$aImportResults['newsid']}_timings")) {
                $aTimingsImportResults = XMLImportTimings(Yii::app()->getConfig('tempdir').DIRECTORY_SEPARATOR.$aFile['filename'], $aImportResults['newsid'], $aImportResults['FieldReMap']);
                $aImportResults = array_merge($aTimingsImportResults, $aImportResults);
                unlink(Yii::app()->getConfig('tempdir').DIRECTORY_SEPARATOR.$aFile['filename']);
                break;
            }
        }
        return $aImportResults;
    } else {
        return null;
    }

}

/**
* This function imports a LimeSurvey .lss survey XML file
*
* @param string $sFullFilePath  The full filepath of the uploaded file
* @param string $sXMLdata
*/
function XMLImportSurvey($sFullFilePath, $sXMLdata = null, $sNewSurveyName = null, $iDesiredSurveyId = null, $bTranslateInsertansTags = true, $bConvertInvalidQuestionCodes = true)
{
    Yii::app()->loadHelper('database');
    $results = [];
    $aGIDReplacements = array();
    if ($sXMLdata === null) {
        $sXMLdata = (string) file_get_contents($sFullFilePath);    
    }

    $xml = @simplexml_load_string($sXMLdata, 'SimpleXMLElement', LIBXML_NONET);

    if (!$xml || $xml->LimeSurveyDocType != 'Survey') {
        $results['error'] = gT("This is not a valid LimeSurvey survey structure XML file.");
        return $results;
    }

    $iDBVersion = (int) $xml->DBVersion;
    $aQIDReplacements = array();
    $aQuestionCodeReplacements = array();
    $aQuotaReplacements = array();
    $results['defaultvalues'] = 0;
    $results['answers'] = 0;
    $results['surveys'] = 0;
    $results['questions'] = 0;
    $results['subquestions'] = 0;
    $results['question_attributes'] = 0;
    $results['groups'] = 0;
    $results['assessments'] = 0;
    $results['quota'] = 0;
    $results['quotals'] = 0;
    $results['quotamembers'] = 0;
    $results['plugin_settings'] = 0;
    $results['survey_url_parameters'] = 0;
    $results['importwarnings'] = array();


    $aLanguagesSupported = array();
    foreach ($xml->languages->language as $language) {
        $aLanguagesSupported[] = (string) $language;
    }

    $results['languages'] = count($aLanguagesSupported);

    // Import surveys table ====================================================
    
    foreach ($xml->surveys->rows->row as $row) {
        $insertdata = array();

        foreach ($row as $key=>$value) {
            $insertdata[(string) $key] = (string) $value;
        }
        $iOldSID = $results['oldsid'] = $insertdata['sid'];

        if ($iDesiredSurveyId != null) {
            $insertdata['wishSID'] = GetNewSurveyID($iDesiredSurveyId);
        } else {
            $insertdata['wishSID'] = $iOldSID;
        }

        if ($iDBVersion < 145) {
            if (isset($insertdata['private'])) {
                $insertdata['anonymized'] = $insertdata['private'];
            }
            unset($insertdata['private']);
            unset($insertdata['notification']);
        }

        //Make sure it is not set active
        $insertdata['active'] = 'N';
        //Set current user to be the owner
        $insertdata['owner_id'] = Yii::app()->session['loginID'];

        if (isset($insertdata['bouncetime']) && $insertdata['bouncetime'] == '') {
            $insertdata['bouncetime'] = null;
        }

        if (isset($insertdata['showXquestions'])) {
            $insertdata['showxquestions'] = $insertdata['showXquestions'];
            unset($insertdata['showXquestions']);
        }

        if (isset($insertdata['googleAnalyticsStyle'])) {
            $insertdata['googleanalyticsstyle'] = $insertdata['googleAnalyticsStyle'];
            unset($insertdata['googleAnalyticsStyle']);
        }

        if (isset($insertdata['googleAnalyticsAPIKey'])) {
            $insertdata['googleanalyticsapikey'] = $insertdata['googleAnalyticsAPIKey'];
            unset($insertdata['googleAnalyticsAPIKey']);
        }

        if (isset($insertdata['allowjumps'])) {
            $insertdata['questionindex'] = ($insertdata['allowjumps'] == "Y") ? 1 : 0;
            unset($insertdata['allowjumps']);
        }

        /* Remove unknow column */
        $aSurveyModelsColumns = Survey::model()->attributes;
        $aSurveyModelsColumns['wishSID'] = null; // To force a sid surely
        $aBadData = array_diff_key($insertdata, $aSurveyModelsColumns);
        $insertdata = array_intersect_key($insertdata, $aSurveyModelsColumns);
        // Fill a optionnal array of error
        foreach ($aBadData as $key=>$value) {
            $results['importwarnings'][] = sprintf(gT("This survey setting has not been imported: %s => %s"), $key, $value);
        }
        $newSurvey = Survey::model()->insertNewSurvey($insertdata);
        if ($newSurvey->sid) {
            $iNewSID = $results['newsid'] = $newSurvey->sid;
            $results['surveys']++;
        } else {
            $results['error'] = gT("Unable to import survey.");
            return $results;
        }
    }


    // Import survey languagesettings table ===================================================================================
    foreach ($xml->surveys_languagesettings->rows->row as $row) {

        $insertdata = array();
        foreach ($row as $key=>$value) {
            $insertdata[(string) $key] = (string) $value;
        }

        if (!in_array($insertdata['surveyls_language'], $aLanguagesSupported)) {
                continue;
        }

        // Assign new survey ID
        $insertdata['surveyls_survey_id'] = $iNewSID;

        // Assign new survey name (if a copy)
        if ($sNewSurveyName != null) {
            $insertdata['surveyls_title'] = $sNewSurveyName;
        }

        if ($bTranslateInsertansTags) {
            $insertdata['surveyls_title'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['surveyls_title']);
            if (isset($insertdata['surveyls_description'])) {
                $insertdata['surveyls_description'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['surveyls_description']);
            }
            if (isset($insertdata['surveyls_welcometext'])) {
                $insertdata['surveyls_welcometext'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['surveyls_welcometext']);
            }
            if (isset($insertdata['surveyls_urldescription'])) {
                $insertdata['surveyls_urldescription'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['surveyls_urldescription']);
            }
            if (isset($insertdata['surveyls_email_invite'])) {
                $insertdata['surveyls_email_invite'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['surveyls_email_invite']);
            }
            if (isset($insertdata['surveyls_email_remind'])) {
                $insertdata['surveyls_email_remind'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['surveyls_email_remind']);
            }
            if (isset($insertdata['surveyls_email_register'])) {
                $insertdata['surveyls_email_register'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['surveyls_email_register']);
            }
            if (isset($insertdata['surveyls_email_confirm'])) {
                $insertdata['surveyls_email_confirm'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['surveyls_email_confirm']);
            }
        }

        if (isset($insertdata['surveyls_attributecaptions']) && substr($insertdata['surveyls_attributecaptions'], 0, 1) != '{') {
            unset($insertdata['surveyls_attributecaptions']);
        }

        SurveyLanguageSetting::model()->insertNewSurvey($insertdata) or safeDie(gT("Error").": Failed to insert data [2]<br />");
    }


    // Import groups table ===================================================================================
    if (isset($xml->groups->rows->row)) {

        foreach ($xml->groups->rows->row as $row) {
            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            $iOldSID = $insertdata['sid'];
            $insertdata['sid'] = $iNewSID;
            $oldgid = $insertdata['gid']; unset($insertdata['gid']); // save the old qid
            $aDataL10n = array();
            if ($iDBVersion < 350) {
                if (!in_array($insertdata['language'], $aLanguagesSupported)) {
                    continue;
                }
                // now translate any links
                if ($bTranslateInsertansTags) {
                    $insertdata['group_name'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['group_name']);
                    $insertdata['description'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['description']);
                }
                $aDataL10n['group_name'] = $insertdata['group_name'];
                $aDataL10n['description'] = $insertdata['description'];
                $aDataL10n['language'] = $insertdata['language'];
                unset($insertdata['group_name']);
                unset($insertdata['description']);
                unset($insertdata['language']);
            }
            if (!isset($aGIDReplacements[$oldgid])) {
                $newgid = QuestionGroup::model()->insertRecords($insertdata) or safeDie(gT("Error").": Failed to insert data [3]<br />");
                $aGIDReplacements[$oldgid] = $newgid; // add old and new qid to the mapping array
                $results['groups']++;
            }
            if (!empty($aDataL10n)) {
                $aDataL10n['gid'] = $aGIDReplacements[$oldgid];
                $oQuestionGroupL10n = new QuestionGroupL10n(); 
                $oQuestionGroupL10n->setAttributes($aDataL10n, false);
                $oQuestionGroupL10n->save();
            }

        }
    }
    if ($iDBVersion >= 350 && isset($xml->group_l10ns->rows->row)) {
        foreach ($xml->group_l10ns->rows->row as $row) {
            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            unset($insertdata['id']);
            if (!in_array($insertdata['language'], $aLanguagesSupported)) {
                continue;
            }
            // now translate any links
            $insertdata['group_name'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['group_name']);
            $insertdata['description'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['description']);
            if (isset($aGIDReplacements[$insertdata['gid']])) {
                $insertdata['gid'] = $aGIDReplacements[$insertdata['gid']];
            } else {
                continue; //Skip invalid group ID
            }
            $oQuestionGroupL10n = new QuestionGroupL10n(); 
            $oQuestionGroupL10n->setAttributes($insertdata, false);
            $oQuestionGroupL10n->save();
        }    
    }
    
    // Import questions table ===================================================================================

    // We have to run the question table data two times - first to find all main questions
    // then for subquestions (because we need to determine the new qids for the main questions first)
    if (isset($xml->questions)) {
// There could be surveys without a any questions.
        foreach ($xml->questions->rows->row as $row) {

            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }

            if ($iDBVersion < 350) {
                if (!in_array($insertdata['language'], $aLanguagesSupported)) {
                    continue;
                }
            }
            if ($insertdata['gid'] == 0) {
                    continue;
            }
            if (!isset($insertdata['mandatory']) || trim($insertdata['mandatory']) == '') {
                $insertdata['mandatory'] = 'N';
            }
            
            $iOldSID = $insertdata['sid'];
            $insertdata['sid'] = $iNewSID;
            $insertdata['gid'] = $aGIDReplacements[$insertdata['gid']];
            $iOldQID = $insertdata['qid']; // save the old qid
            unset($insertdata['qid']); 

            // now translate any links
            if ($iDBVersion < 350) {
                if ($bTranslateInsertansTags) {
                    $insertdata['question'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['question']);
                    $insertdata['help'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['help']);
                }
                $oQuestionL10n = new QuestionL10n();
                $oQuestionL10n->question = $insertdata['question'];
                $oQuestionL10n->help = $insertdata['help'];
                $oQuestionL10n->language = $insertdata['language'];
                unset($insertdata['question']);
                unset($insertdata['help']);
                unset($insertdata['language']);
            }
            if (!$bConvertInvalidQuestionCodes) {
                $sScenario = 'archiveimport';
            } else {
                $sScenario = 'import';
            }

            $oQuestion = new Question($sScenario);
            $oQuestion->setAttributes($insertdata, false);

            if (!isset($aQIDReplacements[$iOldQID])) {
            // Try to fix question title for valid question code enforcement
                if (!$oQuestion->validate(array('title'))) {
                    $sOldTitle = $oQuestion->title;
                    $sNewTitle = preg_replace("/[^A-Za-z0-9]/", '', $sOldTitle);
                    if (is_numeric(substr($sNewTitle, 0, 1))) {
                        $sNewTitle = 'q'.$sNewTitle;
                    }

                    $oQuestion->title = $sNewTitle;
                }

                $attempts = 0;
                // Try to fix question title for unique question code enforcement
                $index = 0;
                $rand = mt_rand(0, 1024);
                while (!$oQuestion->validate(array('title'))) {
                    $sNewTitle = 'r'.$rand.'q'.$index;
                    $index++;
                    $oQuestion->title = $sNewTitle;
                    $attempts++;
                    if ($attempts > 10) {
                        safeDie(gT("Error").": Failed to resolve question code problems after 10 attempts.<br />");
                    }
                }
                if (!$oQuestion->save()) {
                    safeDie(gT("Error while saving: ").print_r($oQuestion->errors, true));
                }
                $aQIDReplacements[$iOldQID] = $oQuestion->qid; ;
                $results['questions']++;
            } 

            if (isset($oQuestionL10n)) {
                $oQuestionL10n->qid = $aQIDReplacements[$iOldQID];
                $oQuestionL10n->save();
                unset($oQuestionL10n);
            }
            // Set a warning if question title was updated
            if (isset($sNewTitle) && isset($sOldTitle)) {
                $results['importwarnings'][] = sprintf(gT("Question code %s was updated to %s."), $sOldTitle, $sNewTitle);
                $aQuestionCodeReplacements[$sOldTitle] = $sNewTitle;
                unset($sNewTitle);
                unset($sOldTitle);
            }
        }
    }

    // Import subquestions -------------------------------------------------------
    if (isset($xml->subquestions)) {

        foreach ($xml->subquestions->rows->row as $row) {
            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }

            if ($iDBVersion < 350) {
                if (!in_array($insertdata['language'], $aLanguagesSupported)) {
                    continue;
                }
            }
            if ($insertdata['gid'] == 0) {
                    continue;
            }            
            if (!isset($insertdata['mandatory']) || trim($insertdata['mandatory']) == '') {
                $insertdata['mandatory'] = 'N';
            }
            $iOldSID = $insertdata['sid'];
            $insertdata['sid'] = $iNewSID;
            $insertdata['gid'] = $aGIDReplacements[(int) $insertdata['gid']];
            $iOldQID = (int) $insertdata['qid']; unset($insertdata['qid']); // save the old qid
            $insertdata['parent_qid'] = $aQIDReplacements[(int) $insertdata['parent_qid']]; // remap the parent_qid
            if ($insertdata) {
                XSSFilterArray($insertdata);
            }
            if (!isset($insertdata['help'])) {
                $insertdata['help'] = '';
            }            // now translate any links
            if ($iDBVersion < 350) {
                if ($bTranslateInsertansTags) {
                    $insertdata['question'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['question']);
                    $insertdata['help'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['help']);
                }
                $oQuestionL10n = new QuestionL10n();
                $oQuestionL10n->question = $insertdata['question'];
                $oQuestionL10n->help = $insertdata['help'];
                $oQuestionL10n->language = $insertdata['language'];
                unset($insertdata['question']);
                unset($insertdata['help']);
                unset($insertdata['language']);
            }
            if (!$bConvertInvalidQuestionCodes) {
                $sScenario = 'archiveimport';
            } else {
                $sScenario = 'import';
            }

            $oQuestion = new Question($sScenario);
            $oQuestion->setAttributes($insertdata, false);

            if (!isset($aQIDReplacements[$iOldQID])) {
                // Try to fix question title for valid question code enforcement
                if (!$oQuestion->validate(array('title'))) {
                    $sOldTitle = $oQuestion->title;
                    $sNewTitle = preg_replace("/[^A-Za-z0-9]/", '', $sOldTitle);
                    if (is_numeric(substr($sNewTitle, 0, 1))) {
                        $sNewTitle = 'sq'.$sNewTitle;
                    }

                    $oQuestion->title = $sNewTitle;
                }

                $attempts = 0;
                // Try to fix question title for unique question code enforcement
                while (!$oQuestion->validate(array('title'))) {

                    if (!isset($index)) {
                        $index = 0;
                        $rand = mt_rand(0, 1024);
                    } else {
                        $index++;
                    }

                    $sNewTitle = 'r'.$rand.'sq'.$index;
                    $oQuestion->title = $sNewTitle;
                    $attempts++;

                    if ($attempts > 10) {
                        safeDie(gT("Error").": Failed to resolve question code problems after 10 attempts.<br />");
                    }
                }
                if (!$oQuestion->save()) {
                    safeDie(gT("Error while saving: ").print_r($oQuestion->errors, true));
                }
                $aQIDReplacements[$iOldQID] = $oQuestion->qid; ;
                $results['questions']++;
            } 

            if (isset($oQuestionL10n)) {
                $oQuestionL10n->qid = $aQIDReplacements[$iOldQID];
                $oQuestionL10n->save();
                unset($oQuestionL10n);
            }

            // Set a warning if question title was updated
            if (isset($sNewTitle) && isset($sOldTitle)) {
                $results['importwarnings'][] = sprintf(gT("Title of subquestion %s was updated to %s."), $sOldTitle, $sNewTitle); // Maybe add the question title ?
                $aQuestionCodeReplacements[$sOldTitle] = $sNewTitle;
                unset($sNewTitle);
                unset($sOldTitle);
            }
        }
    }
    
    //  Import question_l10ns
    if ($iDBVersion >= 350 && isset($xml->question_l10ns->rows->row)) {
        foreach ($xml->question_l10ns->rows->row as $row) {
            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            unset($insertdata['id']);
            // now translate any links
            $insertdata['question'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['question']);
            $insertdata['help'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['help']);
            if (isset($aQIDReplacements[$insertdata['qid']])) {
                $insertdata['qid'] = $aQIDReplacements[$insertdata['qid']];
            } else {
                continue; //Skip invalid group ID
            }
            $oQuestionL10n = new QuestionL10n(); 
            $oQuestionL10n->setAttributes($insertdata, false);
            $oQuestionL10n->save();
        }    
    }

    // Import answers ------------------------------------------------------------
    if (isset($xml->answers)) {

        foreach ($xml->answers->rows->row as $row) {
            $insertdata = array();  

            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            if ($iDBVersion >= 350) {
                $iOldAID = $insertdata['aid'];
                unset($insertdata['aid']);
            }
            if (!isset($aQIDReplacements[(int) $insertdata['qid']])) {
                continue;
            }

            $insertdata['qid'] = $aQIDReplacements[(int) $insertdata['qid']]; // remap the parent_qid
            
            if ($iDBVersion < 350) {
                // now translate any links
                if (!in_array($insertdata['language'], $aLanguagesSupported)) {
                    continue;
                }                 
                if ($bTranslateInsertansTags) {
                    $insertdata['answer'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['answer']);
                }
                $oAnswerL10n = new AnswerL10n();
                $oAnswerL10n->answer = $insertdata['answer'];
                $oAnswerL10n->language = $insertdata['language'];
                unset($insertdata['answer']);
                unset($insertdata['language']);
            }
            
            $oAnswer = new Answer();
            $oAnswer->setAttributes($insertdata, false);
            if ($oAnswer->save() && $iDBVersion >= 350) {
                $aAIDReplacements[$iOldAID] = $oAnswer->aid;
            }
            $results['answers']++;
            if (isset($oAnswerL10n)) {
                $oAnswer = Answer::model()->findByAttributes(['qid'=>$insertdata['qid'], 'code'=>$insertdata['code'], 'scale_id'=>$insertdata['scale_id']]);                
                $oAnswerL10n->aid = $oAnswer->aid;
                $oAnswerL10n->save();
                unset($oAnswerL10n);
            }
        }
    }

    //  Import answer_l10ns
    if ($iDBVersion >= 350 && isset($xml->answer_l10ns->rows->row)) {
        foreach ($xml->answer_l10ns->rows->row as $row) {
            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            unset($insertdata['id']);
            // now translate any links
            if ($bTranslateInsertansTags) {
                $insertdata['answer'] = translateLinks('survey', $iOldSID, $iNewSID, $insertdata['answer']);
            }
            if (isset($aAIDReplacements[$insertdata['aid']])) {
                $insertdata['aid'] = $aAIDReplacements[$insertdata['aid']];
            } else {
                continue; //Skip invalid answer ID
            }
            $oAnswerL10n = new AnswerL10n(); 
            $oAnswerL10n->setAttributes($insertdata, false);
            $oAnswerL10n->save();
        }    
    }    
    
    // Import questionattributes -------------------------------------------------
    if (isset($xml->question_attributes)) {

        $aAllAttributes = questionHelper::getAttributesDefinitions();
        foreach ($xml->question_attributes->rows->row as $row) {

            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }

            // take care of renaming of date min/max adv. attributes fields
            if ($iDBVersion < 170) {

                if (isset($insertdata['attribute'])) {

                    if ($insertdata['attribute'] == 'dropdown_dates_year_max') {
                        $insertdata['attribute'] = 'date_max';
                    }

                    if ($insertdata['attribute'] == 'dropdown_dates_year_min') {
                        $insertdata['attribute'] = 'date_min';
                    }
                }
            }

            unset($insertdata['qaid']);
            if (!isset($aQIDReplacements[(int) $insertdata['qid']])) {
                continue;
            }

            $insertdata['qid'] = $aQIDReplacements[(integer) $insertdata['qid']]; // remap the qid
            if ($iDBVersion < 156 && isset($aAllAttributes[$insertdata['attribute']]['i18n']) && $aAllAttributes[$insertdata['attribute']]['i18n']) {

                foreach ($aLanguagesSupported as $sLanguage) {
                    $insertdata['language'] = $sLanguage;

                    if ($insertdata) {
                        XSSFilterArray($insertdata);
                    }
                    $questionAttribute = new QuestionAttribute();
                    $questionAttribute->attributes = $insertdata;
                    if(!$questionAttribute->save()){
                        safeDie(gT("Error").": Failed to insert data[7]<br />");
                    }

                }
            } else {
                $questionAttribute = new QuestionAttribute();
                $questionAttribute->attributes = $insertdata;
                if(!$questionAttribute->save()){
                    safeDie(gT("Error").": Failed to insert data[8]<br />");
                }
            }

            $results['question_attributes']++;
        }
    }

    // Import defaultvalues ------------------------------------------------------
    if (isset($xml->defaultvalues)) {

        $results['defaultvalues'] = 0;

        foreach ($xml->defaultvalues->rows->row as $row) {
            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            $insertdata['qid'] = $aQIDReplacements[(int) $insertdata['qid']]; // remap the qid
            if (isset($aQIDReplacements[(int) $insertdata['sqid']])) {
// remap the subquestion id   
                $insertdata['sqid'] = $aQIDReplacements[(int) $insertdata['sqid']]; 
            }
            if ($insertdata) {
                            XSSFilterArray($insertdata);
            }
            // now translate any links
            $result = DefaultValue::model()->insertRecords($insertdata) or safeDie(gT("Error").": Failed to insert data[9]<br />");
            $results['defaultvalues']++;
        }
    }
    $aOldNewFieldmap = reverseTranslateFieldNames($iOldSID, $iNewSID, $aGIDReplacements, $aQIDReplacements);

    // Import conditions ---------------------------------------------------------
    if (isset($xml->conditions)) {


        $results['conditions'] = 0;
        $oldcqid = 0;
        $oldqidanscode = 0;
        $oldcgid = 0;
        $oldcsid = 0;
        foreach ($xml->conditions->rows->row as $row) {
            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            // replace the qid for the new one (if there is no new qid in the $aQIDReplacements array it mean that this condition is orphan -> error, skip this record)
            if (isset($aQIDReplacements[$insertdata['qid']])) {
                $insertdata['qid'] = $aQIDReplacements[$insertdata['qid']]; // remap the qid
            } else {
                // a problem with this answer record -> don't consider
                continue; 
            }
            if ($insertdata['cqid'] != 0) {
                if (isset($aQIDReplacements[$insertdata['cqid']])) {
                    $oldcqid = $insertdata['cqid']; //Save for cfield transformation
                    $insertdata['cqid'] = $aQIDReplacements[$insertdata['cqid']]; // remap the qid
                } else {
                    // a problem with this answer record -> don't consider
                    continue; 
                }

                list($oldcsid, $oldcgid, $oldqidanscode) = explode("X", $insertdata["cfieldname"], 3);

                // replace the gid for the new one in the cfieldname(if there is no new gid in the $aGIDReplacements array it means that this condition is orphan -> error, skip this record)
                if (!isset($aGIDReplacements[$oldcgid])) {
                    continue; 
                }
                    
            }

            unset($insertdata["cid"]);

            // recreate the cfieldname with the new IDs
            if ($insertdata['cqid'] != 0) {
                if (preg_match("/^\+/", $oldcsid)) {
                    $newcfieldname = '+'.$iNewSID."X".$aGIDReplacements[$oldcgid]."X".$insertdata["cqid"].substr($oldqidanscode, strlen($oldcqid));
                } else {
                    $newcfieldname = $iNewSID."X".$aGIDReplacements[$oldcgid]."X".$insertdata["cqid"].substr($oldqidanscode, strlen($oldcqid));
                }
            } else {
                // The cfieldname is a not a previous question cfield but a {XXXX} replacement field
                $newcfieldname = $insertdata["cfieldname"];
            }
            $insertdata["cfieldname"] = $newcfieldname;
            if (trim($insertdata["method"]) == '') {
                $insertdata["method"] = '==';
            }

            // Now process the value and replace @sgqa@ codes
            if (preg_match("/^@(.*)@$/", $insertdata["value"], $cfieldnameInCondValue)) {
                if (isset($aOldNewFieldmap[$cfieldnameInCondValue[1]])) {
                    $newvalue = '@'.$aOldNewFieldmap[$cfieldnameInCondValue[1]].'@';
                    $insertdata["value"] = $newvalue;
                }

            }

            // now translate any links
            $result = Condition::model()->insertRecords($insertdata) or safeDie(gT("Error").": Failed to insert data[10]<br />");
            $results['conditions']++;
        }
    }

    // Import assessments --------------------------------------------------------
    if (isset($xml->assessments)) {


        foreach ($xml->assessments->rows->row as $row) {
            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            if ($insertdata['gid'] > 0) {
                $insertdata['gid'] = $aGIDReplacements[(int) $insertdata['gid']]; // remap the qid
            }

            $insertdata['sid'] = $iNewSID; // remap the survey id
            unset($insertdata['id']);
            // now translate any links
            $result = Assessment::model()->insertRecords($insertdata) or safeDie(gT("Error").": Failed to insert data[11]<br />");
            $results['assessments']++;
        }
    }

    // Import quota --------------------------------------------------------------
    if (isset($xml->quota)) {


        foreach ($xml->quota->rows->row as $row) {
            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            $insertdata['sid'] = $iNewSID; // remap the survey id
            $oldid = $insertdata['id'];
            unset($insertdata['id']);
            // now translate any links
            $result = Quota::model()->insertRecords($insertdata) or safeDie(gT("Error").": Failed to insert data[12]<br />");
            $aQuotaReplacements[$oldid] = getLastInsertID('{{quota}}');
            $results['quota']++;
        }
    }

    // Import quota_members ------------------------------------------------------
    if (isset($xml->quota_members)) {

        foreach ($xml->quota_members->rows->row as $row) {
            $quotaMember = new QuotaMember();
            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            $insertdata['sid'] = $iNewSID; // remap the survey id
            $insertdata['qid'] = $aQIDReplacements[(int) $insertdata['qid']]; // remap the qid
            if (isset($insertdata['quota_id'])) {
                $insertdata['quota_id'] = $aQuotaReplacements[(int) $insertdata['quota_id']]; // remap the qid
            }
            unset($insertdata['id']);
            // now translate any links
            $quotaMember->attributes = $insertdata;
            if(!$quotaMember->save()){
                safeDie(gT("Error").": Failed to insert data[13]<br />");
            }
            $results['quotamembers']++;
        }
    }

    // Import quota_languagesettings----------------------------------------------
    if (isset($xml->quota_languagesettings)) {

        foreach ($xml->quota_languagesettings->rows->row as $row) {
            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            $insertdata['quotals_quota_id'] = $aQuotaReplacements[(int) $insertdata['quotals_quota_id']]; // remap the qid
            unset($insertdata['quotals_id']);
            $result = QuotaLanguageSetting::model()->insertRecords($insertdata) or safeDie(gT("Error").": Failed to insert data<br />");
            $results['quotals']++;
        }
    }

    // Import survey_url_parameters ----------------------------------------------
    if (isset($xml->survey_url_parameters)) {

        foreach ($xml->survey_url_parameters->rows->row as $row) {
            $insertdata = array();
            foreach ($row as $key=>$value) {
                $insertdata[(string) $key] = (string) $value;
            }
            $insertdata['sid'] = $iNewSID; // remap the survey id
            if (isset($insertdata['targetsqid']) && $insertdata['targetsqid'] != '') {
                $insertdata['targetsqid'] = $aQIDReplacements[(int) $insertdata['targetsqid']]; // remap the qid
            }
            if (isset($insertdata['targetqid']) && $insertdata['targetqid'] != '') {
                $insertdata['targetqid'] = $aQIDReplacements[(int) $insertdata['targetqid']]; // remap the qid
            }
            unset($insertdata['id']);
            $result = SurveyURLParameter::model()->insertRecord($insertdata) or safeDie(gT("Error").": Failed to insert data[14]<br />");
            $results['survey_url_parameters']++;
        }
    }

    // Import Survey plugins settings
    if (isset($xml->plugin_settings)) {
        $pluginNamesWarning = array(); // To shown not exist warning only one time.
        foreach ($xml->plugin_settings->rows->row as $row) {
            // Find plugin id
            if (isset($row->name)) {
                $oPlugin = Plugin::model()->find("name = :name", array(":name"=>$row->name));
                if ($oPlugin) {
                    $setting = new PluginSetting;
                    $setting->plugin_id = $oPlugin->id;
                    $setting->model = "Survey";
                    $setting->model_id = $iNewSID;
                    $setting->key = (string) $row->key;
                    $setting->value = (string) $row->value;
                    if ($setting->save()) {
                        $results['plugin_settings']++;
                    } else {
                        $results['importwarnings'][] = sprintf(gT("Error when saving %s for plugin %s"), CHtml::encode($row->key), CHtml::encode($row->name));
                    }
                } elseif (!isset($pluginNamesWarning[(string) $row->name])) {
                    $results['importwarnings'][] = sprintf(gT("Plugin %s didn't exist, settings not imported"), CHtml::encode($row->name));
                    $pluginNamesWarning[(string) $row->name] = 1;
                }
            }
        }
    }

    // Set survey rights
    Permission::model()->giveAllSurveyPermissions(Yii::app()->session['loginID'], $iNewSID);
    $aOldNewFieldmap = reverseTranslateFieldNames($iOldSID, $iNewSID, $aGIDReplacements, $aQIDReplacements);
    $results['FieldReMap'] = $aOldNewFieldmap;
    LimeExpressionManager::SetSurveyId($iNewSID);
    translateInsertansTags($iNewSID, $iOldSID, $aOldNewFieldmap);
    replaceExpressionCodes($iNewSID, $aQuestionCodeReplacements);
    if (count($aQuestionCodeReplacements)) {
            array_unshift($results['importwarnings'], "<span class='warningtitle'>".gT('Attention: Several question codes were updated. Please check these carefully as the update  may not be perfect with customized expressions.').'</span>');
    }
    LimeExpressionManager::RevertUpgradeConditionsToRelevance($iNewSID);
    LimeExpressionManager::UpgradeConditionsToRelevance($iNewSID);
    return $results;
}

/**
* This function returns a new random sid if the existing one is taken,
* otherwise it returns the old one.
*
* @param mixed $iDesiredSurveyId
*/
function GetNewSurveyID($iDesiredSurveyId)
{
    Yii::app()->loadHelper('database');
    $aSurvey = Survey::model()->findByPk($iDesiredSurveyId);
    if (!empty($aSurvey) || $iDesiredSurveyId == 0) {
        // Get new random ids until one is found that is not used
        do {
            $iNewSID = randomChars(5, '123456789');
            $aSurvey = Survey::model()->findByPk($iNewSID);
        }
        while (!is_null($aSurvey));

        return $iNewSID;
    } else {
        return $iDesiredSurveyId;
    }
}


/**
 * @param string $sFullFilePath
 * @return mixed
 */
function XMLImportTokens($sFullFilePath, $iSurveyID, $sCreateMissingAttributeFields = true)
{
    Yii::app()->loadHelper('database');
    $survey = Survey::model()->findByPk($iSurveyID);
    $sXMLdata = (string) file_get_contents($sFullFilePath);
    $xml = simplexml_load_string($sXMLdata, 'SimpleXMLElement', LIBXML_NONET);
    $results = [];
    $results['warnings'] = array();
    if ($xml->LimeSurveyDocType != 'Tokens') {
        $results['error'] = gT("This is not a valid token data XML file.");
        return $results;
    }

    if (!isset($xml->tokens->fields)) {
        $results['tokens'] = 0;
        return $results;
    }

    $results['tokens'] = 0;
    $results['tokenfieldscreated'] = 0;

    if ($sCreateMissingAttributeFields) {
        // Get a list with all fieldnames in the XML
        $aXLMFieldNames = array();
        foreach ($xml->tokens->fields->fieldname as $sFieldName) {
            $aXLMFieldNames[] = (string) $sFieldName;
        }
        // Get a list of all fieldnames in the survey participants table
        $aTokenFieldNames = Yii::app()->db->getSchema()->getTable($survey->tokensTableName, true);
        $aTokenFieldNames = array_keys($aTokenFieldNames->columns);
        $aFieldsToCreate = array_diff($aXLMFieldNames, $aTokenFieldNames);
        Yii::app()->loadHelper('update/updatedb');

        foreach ($aFieldsToCreate as $sField) {
            if (strpos($sField, 'attribute') !== false) {
                addColumn($survey->tokensTableName, $sField, 'string');
            }
        }
    }

    switchMSSQLIdentityInsert('tokens_'.$iSurveyID, true);
    foreach ($xml->tokens->rows->row as $row) {
        $insertdata = array();

        foreach ($row as $key=>$value) {
            $insertdata[(string) $key] = (string) $value;
        }

        $token = Token::create($iSurveyID, 'allowinvalidemail');
        $token->setAttributes($insertdata, false);
        if (!$token->save()) {
            $results['warnings'][] = CHtml::errorSummary($token, gT("Skipped tokens entry:"));
        } else {
            $results['tokens']++;
        }
    }
    switchMSSQLIdentityInsert('tokens_'.$iSurveyID, false);
    if (Yii::app()->db->getDriverName() == 'pgsql') {
        try {
            Yii::app()->db->createCommand("SELECT pg_catalog.setval(pg_get_serial_sequence('{{tokens_".$iSurveyID."}}', 'tid'), (SELECT MAX(tid) FROM {{tokens_".$iSurveyID."}}))")->execute(); 
        } catch (Exception $oException) {};
    }
    return $results;
}


/**
 * @param string $sFullFilePath
 * @return mixed
 */
function XMLImportResponses($sFullFilePath, $iSurveyID, $aFieldReMap = array())
{
    Yii::app()->loadHelper('database');
    $survey = Survey::model()->findByPk($iSurveyID);

    switchMSSQLIdentityInsert('survey_'.$iSurveyID, true);
    $results = [];
    $results['responses'] = 0;
    $oXMLReader = new XMLReader();
    $oXMLReader->open($sFullFilePath);
    $DestinationFields = Yii::app()->db->schema->getTable($survey->responsesTableName)->getColumnNames();
    while ($oXMLReader->read()) {
        if ($oXMLReader->name === 'LimeSurveyDocType' && $oXMLReader->nodeType == XMLReader::ELEMENT) {
            $oXMLReader->read();
            if ($oXMLReader->value != 'Responses') {
                $results['error'] = gT("This is not a valid response data XML file.");
                return $results;
            }
        }
        if ($oXMLReader->name === 'rows' && $oXMLReader->nodeType == XMLReader::ELEMENT) {
            while ($oXMLReader->read()) {
                if ($oXMLReader->name === 'row' && $oXMLReader->nodeType == XMLReader::ELEMENT) {
                    $aInsertData = array();
                    while ($oXMLReader->read() && $oXMLReader->name != 'row') {
                        $sFieldname = $oXMLReader->name;
                        if ($sFieldname[0] == '_') {
                            $sFieldname = substr($sFieldname, 1);
                        }
                        $sFieldname = str_replace('-', '#', $sFieldname);
                        if (isset($aFieldReMap[$sFieldname])) {
                            $sFieldname = $aFieldReMap[$sFieldname];
                        }
                        if (!$oXMLReader->isEmptyElement) {
                            $oXMLReader->read();
                            if (in_array($sFieldname, $DestinationFields)) {
// some old response tables contain invalid column names due to old bugs
                                $aInsertData[$sFieldname] = $oXMLReader->value;
                            }
                            $oXMLReader->read();
                        } else {
                            if (in_array($sFieldname, $DestinationFields)) {
                                                            $aInsertData[$sFieldname] = '';
                            }
                        }
                    }

                    SurveyDynamic::model($iSurveyID)->insertRecords($aInsertData) or safeDie(gT("Error").": Failed to insert data[16]<br />");
                    $results['responses']++;
                }
            }

        }
    }

    switchMSSQLIdentityInsert('survey_'.$iSurveyID, false);
    if (Yii::app()->db->getDriverName() == 'pgsql') {
        try {
            Yii::app()->db->createCommand("SELECT pg_catalog.setval(pg_get_serial_sequence('".$survey->responsesTableName."', 'id'), (SELECT MAX(id) FROM ".$survey->responsesTableName."))")->execute(); 
        } catch (Exception $oException) {};
    }
    return $results;
}

/**
* This function imports a CSV file into the response table
*
* @param string $sFullFilePath
* @param integer $iSurveyId
* @param array $aOptions
* Return array $result ("errors","warnings","success")
*/
function CSVImportResponses($sFullFilePath, $iSurveyId, $aOptions = array())
{

    // Default optional
    if (!isset($aOptions['bDeleteFistLine'])) {$aOptions['bDeleteFistLine'] = true; } // By default delete first line (vvimport)
    if (!isset($aOptions['sExistingId'])) {$aOptions['sExistingId'] = "ignore"; } // By default exclude existing id
    if (!isset($aOptions['bNotFinalized'])) {$aOptions['bNotFinalized'] = false; } // By default don't change finalized part
    if (!isset($aOptions['sCharset']) || !$aOptions['sCharset']) {$aOptions['sCharset'] = "utf8"; }
    if (!isset($aOptions['sSeparator'])) {$aOptions['sSeparator'] = "\t"; }
    if (!isset($aOptions['sQuoted'])) {$aOptions['sQuoted'] = "\""; }
    // Fix some part
    if (!array_key_exists($aOptions['sCharset'], aEncodingsArray())) {
        $aOptions['sCharset'] = "utf8";
    }

    // Prepare an array of sentence for result
    $CSVImportResult = array();
    // Read the file
    $handle = fopen($sFullFilePath, "r"); // Need to be adapted for Mac ? in options ?
    if ($handle === false) {
        safeDie("Can't open file");
    }
    while (!feof($handle)) {
        $buffer = fgets($handle); //To allow for very long lines . Another option is fgetcsv (0 to length), but need mb_convert_encoding
        $aFileResponses[] = mb_convert_encoding($buffer, "UTF-8", $aOptions['sCharset']);
    }
    // Close the file
    fclose($handle);
    if ($aOptions['bDeleteFistLine']) {
        array_shift($aFileResponses);
    }

    $aRealFieldNames = Yii::app()->db->getSchema()->getTable(SurveyDynamic::model($iSurveyId)->tableName())->getColumnNames();
    //$aCsvHeader=array_map("trim",explode($aOptions['sSeparator'], trim(array_shift($aFileResponses))));
    $aCsvHeader = str_getcsv(array_shift($aFileResponses), $aOptions['sSeparator'], $aOptions['sQuoted']);
    LimeExpressionManager::SetDirtyFlag(); // Be sure survey EM code are up to date
    $aLemFieldNames = LimeExpressionManager::getLEMqcode2sgqa($iSurveyId);
    $aKeyForFieldNames = array(); // An array assicated each fieldname with corresponding responses key
    if (empty($aCsvHeader)) {
        $CSVImportResult['errors'][] = gT("File seems empty or has only one line");
        return $CSVImportResult;
    }
    // Assign fieldname with $aFileResponses[] key
    foreach ($aRealFieldNames as $sFieldName) {
        if (in_array($sFieldName, $aCsvHeader)) {
// First pass : simple associated
            $aKeyForFieldNames[$sFieldName] = array_search($sFieldName, $aCsvHeader);
        } elseif (in_array($sFieldName, $aLemFieldNames)) {
// Second pass : LEM associated
            $sLemFieldName = array_search($sFieldName, $aLemFieldNames);
            if (in_array($sLemFieldName, $aCsvHeader)) {
                $aKeyForFieldNames[$sFieldName] = array_search($sLemFieldName, $aCsvHeader);
            } elseif ($aOptions['bForceImport']) {
                // as fallback just map questions in order of apperance

                // find out where the answer data columns start in CSV
                if (!isset($csv_ans_start_index)) {
                    foreach ($aCsvHeader as $i=>$name) {
                        if (preg_match('/^\d+X\d+X\d+/', $name)) {
                            $csv_ans_start_index = $i;
                            break;
                        }
                    }
                }
                // find out where the answer data columns start in destination table
                if (!isset($table_ans_start_index)) {
                    foreach ($aRealFieldNames as $i=>$name) {
                        if (preg_match('/^\d+X\d+X\d+/', $name)) {
                            $table_ans_start_index = $i;
                            break;
                        }
                    }
                }

                // map answers in order
                if (isset($table_ans_start_index, $csv_ans_start_index)) {
                    $csv_index = (array_search($sFieldName, $aRealFieldNames) - $table_ans_start_index) + $csv_ans_start_index;
                    if ($csv_index < count($aCsvHeader)) {
                        $aKeyForFieldNames[$sFieldName] = $csv_index;
                    } else {
                        $force_import_failed = true;
                        break;
                    }
                }
            }
        }
    }
    // check if forced error failed
    if (isset($force_import_failed)) {
        $CSVImportResult['errors'][] = gT("Import failed: Forced import was requested but the input file doesn't contain enough columns to fill the survey.");
        return $CSVImportResult;
    }

    // make sure at least one answer was imported before commiting
    foreach ($aKeyForFieldNames as $field=>$index) {
        if (preg_match('/^\d+X\d+X\d+/', $field)) {
            $import_ok = true;
            break;
        }
    }
    if (!isset($import_ok)) {
        $CSVImportResult['errors'][] = gT("Import failed: No answers could be mapped.");
        return $CSVImportResult;
    }

    // Now it's time to import
    // Some var to return
    $iNbResponseLine = 0;
    $aResponsesInserted = array();
    $aResponsesUpdated = array();
    $aResponsesError = array();
    $aExistingsId = array();

    $iMaxId = 0; // If we set the id, keep the max
    // Some specific header (with options)
    $iIdKey = array_search('id', $aCsvHeader); // the id is allways needed and used a lot
    if (is_int($iIdKey)) {unset($aKeyForFieldNames['id']); }
    $iSubmitdateKey = array_search('submitdate', $aCsvHeader); // submitdate can be forced to null
    if (is_int($iSubmitdateKey)) {unset($aKeyForFieldNames['submitdate']); }
    $iIdReponsesKey = (is_int($iIdKey)) ? $iIdKey : 0; // The key for reponses id: id column or first column if not exist

    // Import each responses line here
    while ($sResponses = array_shift($aFileResponses)) {
        $iNbResponseLine++;
        $bExistingsId = false;
        $aResponses = str_getcsv($sResponses, $aOptions['sSeparator'], $aOptions['sQuoted']);
        if ($iIdKey !== false) {
            $oSurvey = SurveyDynamic::model($iSurveyId)->findByPk($aResponses[$iIdKey]);
            if ($oSurvey) {
                $bExistingsId = true;
                $aExistingsId[] = $aResponses[$iIdKey];
                // Do according to option
                switch ($aOptions['sExistingId']) {
                    case 'replace':
                        SurveyDynamic::model($iSurveyId)->deleteByPk($aResponses[$iIdKey]);
                        SurveyDynamic::sid($iSurveyId);
                        $oSurvey = new SurveyDynamic;
                        break;
                    case 'replaceanswers':
                        break;
                    case 'renumber':
                        SurveyDynamic::sid($iSurveyId);
                        $oSurvey = new SurveyDynamic;
                        break;
                    case 'skip':
                    case 'ignore':
                    default:
                        $oSurvey = false; // Remove existing survey : don't import again
                        break;
                }
            } else {
                SurveyDynamic::sid($iSurveyId);
                $oSurvey = new SurveyDynamic;
            }
        } else {
            SurveyDynamic::sid($iSurveyId);
            $oSurvey = new SurveyDynamic;
        }
        if ($oSurvey) {
            // First rule for id and submitdate
            if (is_int($iIdKey)) {
// Rule for id: only if id exists in vvimport file
                if (!$bExistingsId) {
// If not exist : allways import it
                    $oSurvey->id = $aResponses[$iIdKey];
                    $iMaxId = ($aResponses[$iIdKey] > $iMaxId) ? $aResponses[$iIdKey] : $iMaxId;
                } elseif ($aOptions['sExistingId'] == 'replace' || $aOptions['sExistingId'] == 'replaceanswers') {
                    // Set it depending with some options
                    $oSurvey->id = $aResponses[$iIdKey];
                }
            }
            if ($aOptions['bNotFinalized']) {
                $oSurvey->submitdate = new CDbExpression('NULL');
            } elseif (is_int($iSubmitdateKey)) {
                if ($aResponses[$iSubmitdateKey] == '{question_not_shown}' || trim($aResponses[$iSubmitdateKey] == '')) {
                    $oSurvey->submitdate = new CDbExpression('NULL');
                } else {
                    // Maybe control valid date : see http://php.net/manual/en/function.checkdate.php#78362 for example
                    $oSurvey->submitdate = $aResponses[$iSubmitdateKey];
                }
            }
            foreach ($aKeyForFieldNames as $sFieldName=>$iFieldKey) {
                if ($aResponses[$iFieldKey] == '{question_not_shown}') {
                    $oSurvey->$sFieldName = new CDbExpression('NULL');
                } else {
                    $sResponse = str_replace(array("{quote}", "{tab}", "{cr}", "{newline}", "{lbrace}"), array("\"", "\t", "\r", "\n", "{"), $aResponses[$iFieldKey]);
                    $oSurvey->$sFieldName = $sResponse;
                }
            }
            // We use transaction to prevent DB error
            $oTransaction = Yii::app()->db->beginTransaction();
            try {
                if (isset($oSurvey->id) && !is_null($oSurvey->id)) {
                    switchMSSQLIdentityInsert('survey_'.$iSurveyId, true);
                    $bSwitched = true;
                }
                if ($oSurvey->save()) {
                    $beforeDataEntryImport = new PluginEvent('beforeDataEntryImport');
                    $beforeDataEntryImport->set('iSurveyID', $iSurveyId);
                    $beforeDataEntryImport->set('oModel', $oSurvey);
                    App()->getPluginManager()->dispatchEvent($beforeDataEntryImport);

                    $oTransaction->commit();
                    if ($bExistingsId && $aOptions['sExistingId'] != 'renumber') {
                        $aResponsesUpdated[] = $aResponses[$iIdReponsesKey];
                    } else {
                        $aResponsesInserted[] = $aResponses[$iIdReponsesKey];
                    }
                } else {
                    // Actually can not be, leave it if we have a $oSurvey->validate() in future release                    
                    $oTransaction->rollBack();
                    $aResponsesError[] = $aResponses[$iIdReponsesKey];
                }
                if (isset($bSwitched) && $bSwitched == true) {
                    switchMSSQLIdentityInsert('survey_'.$iSurveyId, false);
                    $bSwitched = false;
                }
            } catch (Exception $oException) {
                $oTransaction->rollBack();
                $aResponsesError[] = $aResponses[$iIdReponsesKey];
                // Show some error to user ?
                // $CSVImportResult['errors'][]=$oException->getMessage(); // Show it in view
                // tracevar($oException->getMessage());// Show it in console (if debug is set)
            }

        }
    }
    // Fix max next id (for pgsql)
    // mysql dot need fix, but what for mssql ?
    // Do a model function for this can be a good idea (see activate_helper/activateSurvey)
    if (Yii::app()->db->driverName == 'pgsql') {
        $sSequenceName = Yii::app()->db->getSchema()->getTable("{{survey_{$iSurveyId}}}")->sequenceName;
        $iActualSerial = Yii::app()->db->createCommand("SELECT last_value FROM  {$sSequenceName}")->queryScalar();
        if ($iActualSerial < $iMaxId) {
            $sQuery = "SELECT setval(pg_get_serial_sequence('{{survey_{$iSurveyId}}}', 'id'),{$iMaxId},false);";
            try {
                Yii::app()->db->createCommand($sQuery)->execute();
            } catch (Exception $oException) {};
        }
    }

    // End of import
    // Construction of returned information
    if ($iNbResponseLine) {
        $CSVImportResult['success'][] = sprintf(gT("%s response lines in your file."), $iNbResponseLine);
    } else {
        $CSVImportResult['errors'][] = gT("No response lines in your file.");
    }
    if (count($aResponsesInserted)) {
        $CSVImportResult['success'][] = sprintf(gT("%s responses were inserted."), count($aResponsesInserted));
        // Maybe add implode aResponsesInserted array
    }
    if (count($aResponsesUpdated)) {
        $CSVImportResult['success'][] = sprintf(gT("%s responses were updated."), count($aResponsesUpdated));
    }
    if (count($aResponsesError)) {
        $CSVImportResult['errors'][] = sprintf(gT("%s responses cannot be inserted or updated."), count($aResponsesError));
    }
    if (count($aExistingsId) && ($aOptions['sExistingId'] == 'skip' || $aOptions['sExistingId'] == 'ignore')) {
        $CSVImportResult['warnings'][] = sprintf(gT("%s responses already exist."), count($aExistingsId));
    }
    return $CSVImportResult;
}


/**
 * @param string $sFullFilePath
 */
function XMLImportTimings($sFullFilePath, $iSurveyID, $aFieldReMap = array())
{

    Yii::app()->loadHelper('database');

    $sXMLdata = (string) file_get_contents($sFullFilePath);
    $xml = simplexml_load_string($sXMLdata, 'SimpleXMLElement', LIBXML_NONET);
    $results = [];
    if ($xml->LimeSurveyDocType != 'Timings') {
        $results['error'] = gT("This is not a valid timings data XML file.");
        return $results;
    }

    $results['responses'] = 0;

    $aLanguagesSupported = array();
    foreach ($xml->languages->language as $language) {
        $aLanguagesSupported[] = (string) $language;
    }
    $results['languages'] = count($aLanguagesSupported);
        // Return if there are no timing records to import
    if (!isset($xml->timings->rows)) {
        return $results;
    }
    foreach ($xml->timings->rows->row as $row) {
        $insertdata = array();

        foreach ($row as $key=>$value) {
            if ($key[0] == '_') {
                $key = substr($key, 1);
            }
            if (isset($aFieldReMap[substr($key, 0, -4)])) {
                $key = $aFieldReMap[substr($key, 0, -4)].'time';
            }
            $insertdata[$key] = (string) $value;
        }

        SurveyTimingDynamic::model($iSurveyID)->insertRecords($insertdata) or safeDie(gT("Error").": Failed to insert data[17]<br />");

        $results['responses']++;
    }
    return $results;
}


function XSSFilterArray(&$array)
{
    if (Yii::app()->getConfig('filterxsshtml') && !Permission::model()->hasGlobalPermission('superadmin', 'read')) {
        $filter = new CHtmlPurifier();
        $filter->options = array('URI.AllowedSchemes'=>array(
        'http' => true,
        'https' => true,
        ));
        foreach ($array as &$value) {
            $value = $filter->purify($value);
        }
    }
}

/**
* Import survey from an TSV file template that does not require or allow assigning of GID or QID values.
* NOTE:  This currently only supports import of one language
* @param string $sFullFilePath
* @return array
*
* @author TMSWhite
*/
function TSVImportSurvey($sFullFilePath)
{


    $results = array();
    $results['error'] = false;
    $baselang = 'en'; // TODO set proper default

    $handle = fopen($sFullFilePath, 'r');
    if ($handle === false) {
        safeDie("Can't open file");
    }
    $bom = fread($handle, 2);
    rewind($handle);
    $aAttributeList = array(); //QuestionAttribute::getQuestionAttributesSettings();

    // Excel tends to save CSV as UTF-16, which PHP does not properly detect
    if ($bom === chr(0xff).chr(0xfe) || $bom === chr(0xfe).chr(0xff)) {
        // UTF16 Byte Order Mark present
        $encoding = 'UTF-16';
    } else {
        $file_sample = (string) fread($handle, 1000).'e'; //read first 1000 bytes
        // + e is a workaround for mb_string bug
        rewind($handle);

        $encoding = mb_detect_encoding($file_sample, 'UTF-8, UTF-7, ASCII, EUC-JP,SJIS, eucJP-win, SJIS-win, JIS, ISO-2022-JP');
    }
    if ($encoding !== false && $encoding != 'UTF-8') {
        stream_filter_append($handle, 'convert.iconv.'.$encoding.'/UTF-8');
    }

    $file = stream_get_contents($handle);
    fclose($handle);
    // fix Excel non-breaking space
    $file = str_replace("0xC20xA0", ' ', $file);
    // Replace all different newlines styles with \n
    $file = preg_replace('~\R~u', "\n", $file);
    $tmp = fopen('php://temp', 'r+');
    fwrite($tmp, $file);
    rewind($tmp);
    $rowheaders = fgetcsv($tmp, 0, "\t", '"');
    $rowheaders = array_map('trim', $rowheaders);
    // remove BOM from the first header cell, if needed
    $rowheaders[0] = preg_replace("/^\W+/", "", $rowheaders[0]);
    if (preg_match('/class$/', $rowheaders[0])) {
        $rowheaders[0] = 'class'; // second attempt to remove BOM
    }

    $adata = array();
    $iHeaderCount = count($rowheaders);
    while (($row = fgetcsv($tmp, 0, "\t", '"')) !== false) {
        $rowarray = array();
        for ($i = 0; $i < $iHeaderCount; ++$i) {
            $val = (isset($row[$i]) ? $row[$i] : '');
            // if Excel was used, it surrounds strings with quotes and doubles internal double quotes.  Fix that.
            if (preg_match('/^".*"$/', $val)) {
                $val = str_replace('""', '"', substr($val, 1, -1));
            }
            $rowarray[$rowheaders[$i]] = $val;
        }
        $adata[] = $rowarray;
    }
    fclose($tmp);
    $results['defaultvalues'] = 0;
    $results['answers'] = 0;
    $results['surveys'] = 0;
    $results['languages'] = 0;
    $results['questions'] = 0;
    $results['subquestions'] = 0;
    $results['question_attributes'] = 0;
    $results['groups'] = 0;
    $results['importwarnings'] = array();
    // these aren't used here, but are needed to avoid errors in post-import display
    $results['assessments'] = 0;
    $results['quota'] = 0;
    $results['quotamembers'] = 0;
    $results['quotals'] = 0;

    // collect information about survey and its language settings
    $surveyinfo = array();
    $surveyls = array();
    foreach ($adata as $row) {
        switch ($row['class']) {
            case 'S':
                if (isset($row['text']) && $row['name'] != 'datecreated') {
                    $surveyinfo[$row['name']] = $row['text'];
                }
                break;
            case 'SL':
                if (!isset($surveyls[$row['language']])) {
                    $surveyls[$row['language']] = array();
                }
                if (isset($row['text'])) {
                    $surveyls[$row['language']][$row['name']] = $row['text'];
                }
                break;
        }
    }


    // Create the survey entry
    $surveyinfo['startdate'] = null;
    $surveyinfo['active'] = 'N';
    // unset($surveyinfo['datecreated']);
    $newSurvey = Survey::model()->insertNewSurvey($surveyinfo); //or safeDie(gT("Error").": Failed to insert survey<br />");

    if (!$newSurvey->sid) {
        $results['error'] = CHtml::errorSummary($newSurvey, gT("Error(s) when try to create survey"));
        $results['bFailed'] = true;
        return $results;
    }
    $iNewSID = $newSurvey->sid;
    $surveyinfo['sid'] = $iNewSID;
    $results['surveys']++;
    $results['newsid'] = $iNewSID;

    $gid = 0;
    $gseq = 0; // group_order
    $qid = 0;
    $qseq = 0; // question_order
    $qtype = 'T';
    $aseq = 0; // answer sortorder

    // set the language for the survey
    $_title = 'Missing Title';
    foreach ($surveyls as $_lang => $insertdata) {
        $insertdata['surveyls_survey_id'] = $iNewSID;
        $insertdata['surveyls_language'] = $_lang;
        if (isset($insertdata['surveyls_title'])) {
            $_title = $insertdata['surveyls_title'];
        } else {
            $insertdata['surveyls_title'] = $_title;
        }


        $result = SurveyLanguageSetting::model()->insertNewSurvey($insertdata); //
        if (!$result) {
            $results['error'][] = gT("Error")." : ".gT("Failed to insert survey language");
            break;
        }
        $results['languages']++;
    }

    $ginfo = array();
    $qinfo = array();
    $sqinfo = array();

    if (isset($surveyinfo['language'])) {
        $baselang = $surveyinfo['language']; // the base language
    }

    $rownumber = 1;
    $lastglang = '';
    $lastother = 'N';
    $qseq = 0;
    $iGroupcounter = 0;
    foreach ($adata as $row) {
        $rownumber += 1;
        switch ($row['class']) {
            case 'G':
                // insert group
                $insertdata = array();
                $insertdata['sid'] = $iNewSID;
                $gname = ((!empty($row['name']) ? $row['name'] : 'G'.$gseq));
                $glang = (!empty($row['language']) ? $row['language'] : $baselang);
                // when a multi-lang tsv-file without information on the group id/number (old style) is imported,
                // we make up this information by giving a number 0..[numberofgroups-1] per language.
                // the number and order of groups per language should be the same, so we can also import these files
                if ($lastglang != $glang) {
//reset counter on language change
                    $iGroupcounter = 0;
                }
                $lastglang = $glang;
                //use group id/number from file. if missing, use an increasing number (s.a.)
                $sGroupseq = (!empty($row['type/scale']) ? $row['type/scale'] : 'G'.$iGroupcounter++);
                $insertdata['group_name'] = $gname;
                $insertdata['grelevance'] = (isset($row['relevance']) ? $row['relevance'] : '');
                $insertdata['description'] = (isset($row['text']) ? $row['text'] : '');
                $insertdata['language'] = $glang;
                $insertdata['randomization_group'] = (isset($row['random_group']) ? $row['random_group'] : '');

                // For multi language survey: same gid/sort order across all languages
                if (isset($ginfo[$sGroupseq])) {
                    $gid = $ginfo[$sGroupseq]['gid'];
                    $insertdata['gid'] = $gid;
                    $insertdata['group_order'] = $ginfo[$sGroupseq]['group_order'];
                } else {
                    $insertdata['group_order'] = $gseq;
                }
                $newgid = QuestionGroup::model()->insertRecords($insertdata);
                if (!$newgid) {
                    $results['error'][] = gT("Error")." : ".gT("Failed to insert group").". ".gT("Text file row number ").$rownumber." (".$gname.")";
                    break;
                }
                if (!isset($ginfo[$sGroupseq])) {
                    $results['groups']++;
                    $gid = $newgid;
                    $ginfo[$sGroupseq]['gid'] = $gid;
                    $ginfo[$sGroupseq]['group_order'] = $gseq++;
                }
                $qseq = 0; // reset the question_order
                break;

            case 'Q':
                // insert question
                $insertdata = array();
                $insertdata['sid'] = $iNewSID;
                $qtype = (isset($row['type/scale']) ? $row['type/scale'] : 'T');
                $qname = (isset($row['name']) ? $row['name'] : 'Q'.$qseq);
                $insertdata['gid'] = $gid;
                $insertdata['type'] = $qtype;
                $insertdata['title'] = $qname;
                $insertdata['question'] = (isset($row['text']) ? $row['text'] : '');
                $insertdata['relevance'] = (isset($row['relevance']) ? $row['relevance'] : '');
                $insertdata['preg'] = (isset($row['validation']) ? $row['validation'] : '');
                $insertdata['help'] = (isset($row['help']) ? $row['help'] : '');
                $insertdata['language'] = (isset($row['language']) ? $row['language'] : $baselang);
                $insertdata['mandatory'] = (isset($row['mandatory']) ? $row['mandatory'] : '');
                $lastother = $insertdata['other'] = (isset($row['other']) ? $row['other'] : 'N'); // Keep trace of other settings for sub question
                $insertdata['same_default'] = (isset($row['same_default']) ? $row['same_default'] : 0);
                $insertdata['parent_qid'] = 0;

                // For multi numeric survey : same name, add the gid to have same name on different gid. Bad for EM.
                $fullqname = 'G'.$gid.'_'.$qname;
                if (isset($qinfo[$fullqname])) {
                    $qseq = $qinfo[$fullqname]['question_order'];
                    $qid = $qinfo[$fullqname]['qid'];
                    $insertdata['qid'] = $qid;
                    $insertdata['question_order'] = $qseq;
                } else {
                    $insertdata['question_order'] = $qseq;
                }
                $question = new Question();
                $question->setAttributes($insertdata, false);
                if (!$question->save()) {
                    $results['error'][] = gT("Error")." : ".gT("Could not insert question").". ".gT("Text file row number ").$rownumber." (".$qname.")";
                    break;
                }
                $newqid = $question->qid;
                if (!isset($qinfo[$fullqname])) {
                    $results['questions']++;
                    $qid = $newqid; // save this for later
                    $qinfo[$fullqname]['qid'] = $qid;
                    $qinfo[$fullqname]['question_order'] = $qseq++;
                }
                $aseq = 0; //reset the answer sortorder
                $sqseq = 0; //reset the sub question sortorder
                // insert question attributes
                foreach ($row as $key=>$val) {
                    switch ($key) {
                        case 'class':
                        case 'type/scale':
                        case 'name':
                        case 'text':
                        case 'validation':
                        case 'relevance':
                        case 'help':
                        case 'language':
                        case 'mandatory':
                        case 'other':
                        case 'same_default':
                        case 'default':
                            break;
                        default:
                            if ($key != '' && $val != '') {
                                $questionAttribute = new QuestionAttribute();
                                $questionAttribute->qid = $qid;
                                // check if attribute is a i18n attribute. If yes, set language, else set language to null in attribute table
                                $aAttributeList[$qtype] = questionHelper::getQuestionAttributesSettings($qtype);
                                if ($aAttributeList[$qtype][$key]['i18n']) {
                                    $questionAttribute->language = (isset($row['language']) ? $row['language'] : $baselang);
                                }
                                $questionAttribute->attribute = $key;
                                $questionAttribute->value = $val;

                                if (!$questionAttribute->save()) {
                                    $results['importwarnings'][] = gT("Warning")." : ".gT("Failed to insert question attribute").". ".gT("Text file row number ").$rownumber." ({$key})";
                                    break;
                                }
                                $results['question_attributes']++;
                            }
                            break;
                    }
                }

                // insert default value
                if (isset($row['default']) && $row['default'] !== "") {
                    $insertdata = array();
                    $insertdata['qid'] = $qid;
                    $insertdata['language'] = (isset($row['language']) ? $row['language'] : $baselang);
                    $insertdata['defaultvalue'] = $row['default'];
                    $result = DefaultValue::model()->insertRecords($insertdata);
                    if (!$result) {
                        $results['importwarnings'][] = gT("Warning")." : ".gT("Failed to insert default value").". ".gT("Text file row number ").$rownumber;
                        break;
                    }
                    $results['defaultvalues']++;
                }
                break;

            case 'SQ':
                $sqname = (isset($row['name']) ? $row['name'] : 'SQ'.$sqseq);
                $sqid = '';
                if ($qtype == Question::QT_O_LIST_WITH_COMMENT || $qtype == Question::QT_VERTICAL_FILE_UPLOAD) {
                    ;   // these are fake rows to show naming of comment and filecount fields
                } elseif ($sqname == 'other' && $lastother == "Y") {
// If last question have other to Y : it's not a real SQ row
                    if ($qtype == Question::QT_EXCLAMATION_LIST_DROPDOWN || $qtype == Question::QT_L_LIST_DROPDOWN) {
                        // only used to set default value for 'other' in these cases
                        if (isset($row['default']) && $row['default'] != "") {
                            $insertdata = array();
                            $insertdata['qid'] = $qid;
                            $insertdata['specialtype'] = 'other';
                            $insertdata['language'] = (isset($row['language']) ? $row['language'] : $baselang);
                            $insertdata['defaultvalue'] = $row['default'];
                            $result = DefaultValue::model()->insertRecords($insertdata);
                            if (!$result) {
                                $results['importwarnings'][] = gT("Warning")." : ".gT("Failed to insert default value").". ".gT("Text file row number ").$rownumber;
                                break;
                            }
                            $results['defaultvalues']++;
                        }
                    }
                } else {
                    $insertdata = array();
                    $scale_id = (isset($row['type/scale']) ? $row['type/scale'] : 0);
                    $insertdata['sid'] = $iNewSID;
                    $insertdata['gid'] = $gid;
                    $insertdata['parent_qid'] = $qid;
                    $insertdata['type'] = $qtype;
                    $insertdata['title'] = $sqname;
                    $insertdata['question'] = (isset($row['text']) ? $row['text'] : '');
                    $insertdata['relevance'] = (isset($row['relevance']) ? $row['relevance'] : '');
                    $insertdata['preg'] = (isset($row['validation']) ? $row['validation'] : '');
                    $insertdata['help'] = (isset($row['help']) ? $row['help'] : '');
                    $insertdata['language'] = (isset($row['language']) ? $row['language'] : $baselang);
                    $insertdata['mandatory'] = (isset($row['mandatory']) ? $row['mandatory'] : '');
                    $insertdata['scale_id'] = $scale_id;
                    // For multi nueric language, qid is needed, why not gid. name is not unique.
                    $fullsqname = 'G'.$gid.'Q'.$qid.'_'.$scale_id.'_'.$sqname;
                    if (isset($sqinfo[$fullsqname])) {
                        $qseq = $sqinfo[$fullsqname]['question_order'];
                        $sqid = $sqinfo[$fullsqname]['sqid'];
                        $insertdata['question_order'] = $qseq;
                        $insertdata['qid'] = $sqid;
                    } else {
                        $insertdata['question_order'] = $qseq;
                    }
                    // Insert sub question and keep the sqid for multi language survey
                    $question = new Question();
                    $question->attributes = $insertdata;
                    if (!$question->save()) {
                        $results['error'][] = gT("Error")." : ".gT("Could not insert subquestion").". ".gT("Text file row number ").$rownumber." (".$sqname.")";
                        break;
                    }

                    if (!isset($sqinfo[$fullsqname])) {
                        $sqinfo[$fullsqname]['question_order'] = $qseq++;
                        $sqid = $question->qid; // save this for later
                        $sqinfo[$fullsqname]['sqid'] = $sqid;
                        $results['subquestions']++;
                    }

                    // insert default value
                    if (isset($row['default']) && $row['default'] != "") {
                        $insertdata = array();
                        $insertdata['qid'] = $qid;
                        $insertdata['sqid'] = $sqid;
                        $insertdata['scale_id'] = $scale_id;
                        $insertdata['language'] = (isset($row['language']) ? $row['language'] : $baselang);
                        $insertdata['defaultvalue'] = $row['default'];
                        $result = DefaultValue::model()->insertRecords($insertdata);
                        if (!$result) {
                            $results['importwarnings'][] = gT("Warning")." : ".gT("Failed to insert default value").". ".gT("Text file row number ").$rownumber;
                            break;
                        }
                        $results['defaultvalues']++;
                    }
                }
                break;
            case 'A':
                $insertdata = array();
                $insertdata['qid'] = $qid;
                $insertdata['code'] = (isset($row['name']) ? $row['name'] : 'A'.$aseq);
                $insertdata['answer'] = (isset($row['text']) ? $row['text'] : '');
                $insertdata['scale_id'] = (isset($row['type/scale']) ? $row['type/scale'] : 0);
                $insertdata['language'] = (isset($row['language']) ? $row['language'] : $baselang);
                $insertdata['assessment_value'] = (int) (isset($row['relevance']) ? $row['relevance'] : '');
                $insertdata['sortorder'] = ++$aseq;
                $answer = new Answer();
                $answer->attributes = $insertdata;

                if (!$answer->save()) {
                    $results['error'][] = gT("Error")." : ".gT("Could not insert answer").". ".gT("Text file row number ").$rownumber;
                }
                $results['answers']++;
                break;
        }

    }

    // Delete the survey if error found
    if (is_array($results['error'])) {
        Survey::model()->deleteSurvey($iNewSID);
    } else {
        LimeExpressionManager::SetSurveyId($iNewSID);
        LimeExpressionManager::RevertUpgradeConditionsToRelevance($iNewSID);
        LimeExpressionManager::UpgradeConditionsToRelevance($iNewSID);
    }

    return $results;
}

/**
* This function switches identity insert on/off for the MSSQL database
*
* @param string $table table name (without prefix)
* @param boolean $state  Set to true to activate ID insert, or false to deactivate
*/
function switchMSSQLIdentityInsert($table, $state)
{
    if (in_array(Yii::app()->db->getDriverName(), array('mssql', 'sqlsrv', 'dblib'))) {
        if ($state === true) {
            // This needs to be done directly on the PDO object because when using CdbCommand or similar it won't have any effect
            Yii::app()->db->pdoInstance->exec('SET IDENTITY_INSERT '.Yii::app()->db->tablePrefix.$table.' ON');
        } else {
            // This needs to be done directly on the PDO object because when using CdbCommand or similar it won't have any effect
            Yii::app()->db->pdoInstance->exec('SET IDENTITY_INSERT '.Yii::app()->db->tablePrefix.$table.' OFF');
        }
    }
}
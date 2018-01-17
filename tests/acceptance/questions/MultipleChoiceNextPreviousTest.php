<?php
namespace LimeSurvey\tests\acceptance\question;

use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\Exception\NoSuchElementException;
use LimeSurvey\tests\TestBaseClassWeb;

/**
 *  LimeSurvey
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
 * @since 2017-11-14
 * @group multiplechoice
 */
class MultipleChoiceNextPreviousTest extends TestBaseClassWeb
{
    /**
     * Import survey in tests/surveys/.
     */
    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
    }
    /**
     *
     */
    public function testNextPrevious()
    {
        // Import survey.
        $surveyFile = self::$surveysFolder . '/limesurvey_survey_583999.lss';
        self::importSurvey($surveyFile);
        // Go to preview.
        $urlMan = \Yii::app()->urlManager;
        $urlMan->setBaseUrl('http://' . self::$domain . '/index.php');
        $url = $urlMan->createUrl(
            'survey/index',
            [
                'sid' => self::$surveyId,
                'newtest' => 'Y',
                'lang' => 'pt'
            ]
        );
        // Get questions.
        $survey = \Survey::model()->findByPk(self::$surveyId);
        $questionObjects = $survey->groups[0]->questions;
        $questions = [];
        foreach ($questionObjects as $q) {
            $questions[$q->title] = $q;
        }
        try {
            self::$webDriver->get($url);
            // Click first checkbox.
            $lis = self::$webDriver->findElements(WebDriverBy::cssSelector('li label'));
            $this->assertCount(3, $lis);
            $lis[0]->click();
            // Click next.
            $submit = self::$webDriver->findElement(WebDriverBy::id('ls-button-submit'));
            $submit->click();
            // Click previous..
            $prev = self::$webDriver->findElement(WebDriverBy::id('ls-button-previous'));
            $prev->click();
            sleep(1);  // TODO: Does not work without this.
            // Click next.
            $submit = self::$webDriver->findElement(WebDriverBy::id('ls-button-submit'));
            $submit->click();
            // Click previous..
            $prev = self::$webDriver->findElement(WebDriverBy::id('ls-button-previous'));
            $prev->click();
            // Check value of checkbox.
            $sgqa = self::$surveyId . 'X' . $survey->groups[0]->gid . 'X' . $questions['q2']->qid;
            $checkbox = self::$webDriver->findElement(WebDriverBy::id('java' . $sgqa . 'SQ001'));
            $this->assertEquals('Y', $checkbox->getAttribute('value'));
        } catch (NoSuchElementException $ex) {
            $screenshot = self::$webDriver->takeScreenshot();
            $filename = self::$screenshotsFolder.'/MultipleChoiceNextPreviousTest.png';
            file_put_contents($filename, $screenshot);
            $this->assertFalse(
                true,
                'Url: ' . $url . PHP_EOL .
                'Screenshot in ' .$filename . PHP_EOL . $ex->getMessage()
            );
        }
    }
}
<?php
/**
 * This extension is needed to add complex functions to twig, needing specific process (like accessing config datas).
 * Most of the calls to internal functions don't need to be set here, but can be directly added to the internal config file.
 * For example, the calls to encode, gT and eT don't need any extra parameters or process, so they are added as filters in the congif/internal.php:
 *
 * 'filters' => array(
 *     'jencode' => 'CJSON::encode',
 *     't'     => 'eT',
 *     'gT'    => 'gT',
 * ),
 *
 * So you only add functions here when they need a specific process while called via Twig.
 * To add an advanced function to twig:
 *
 * 1. Add it here as a static public function
 *      eg:
 *          static public function foo($bar)
 *          {
 *              return procces($bar);
 *          }
 *
 * 2. Add it in config/internal.php as a function, and as an allowed function in the sandbox
 *      eg:
 *          twigRenderer' => array(
 *              ...
 *              'functions' => array(
 *                  ...
 *                  'foo' => 'LS_Twig_Extension::foo',
 *                ...),
 *              ...
 *              'sandboxConfig' => array(
 *              ...
 *                  'functions' => array('include', ..., 'foo')
 *                 ),
 *
 * Now you access this function in any twig file via: {{ foo($bar) }}, it will show the result of process($bar).
 * If LS_Twig_Extension::foo() returns some HTML, by default the HTML will be escaped and shows as text.
 * To get the pure HTML, just do: {{ foo($bar) | raw }}
 */


class LS_Twig_Extension extends Twig_Extension
{

    /**
     * Publish a css file from public style directory, using or not the asset manager (depending on configuration)
     * In any twig file, you can register a public css file doing: {{ registerPublicCssFile($sPublicCssFileName) }}
     * @param string $sPublicCssFileName name of the CSS file to publish in public style directory
     */
    public static function registerPublicCssFile($sPublicCssFileName)
    {
        Yii::app()->getClientScript()->registerCssFile(
            Yii::app()->getConfig('publicstyleurl').
            $sPublicCssFileName
        );
    }


    /**
     * Publish a css file from template directory, using or not the asset manager (depending on configuration)
     * In any twig file, you can register a template css file doing: {{ registerTemplateCssFile($sTemplateCssFileName) }}
     * @param string $sTemplateCssFileName name of the CSS file to publish in template directory (it should contains the subdirectories)
     */
    public static function registerTemplateCssFile($sTemplateCssFileName)
    {
        /*
            CSS added from template could require some files from the template folder file...  (eg: background.css)
            So, if we want the statements like :
              url("../files/myfile.jpg)
             to point to an existing file, the css file must be published in the same tmp directory than the template files
             in other words, the css file must be added to the template package.
        */

        $oTemplate = self::getTemplateForRessource($sTemplateCssFileName);
        Yii::app()->getClientScript()->packages[$oTemplate->sPackageName]['css'][] = $sTemplateCssFileName;
    }

    /**
     * Publish a script file from general script directory, using or not the asset manager (depending on configuration)
     * In any twig file, you can register a general script file doing: {{ registerGeneralScript($sGeneralScriptFileName) }}
     * @param string $sGeneralScriptFileName name of the script file to publish in general script directory (it should contains the subdirectories)
     * @param string $position
     * @param array $htmlOptions
     */
    public static function registerGeneralScript($sGeneralScriptFileName, $position = null, array $htmlOptions = array())
    {
        $position = self::getPosition($position);
        Yii::app()->getClientScript()->registerScriptFile(
            App()->getConfig('generalscripts').
            $sGeneralScriptFileName,
            $position,
            $htmlOptions
        );
    }

    /**
     * Publish a script file from template directory, using or not the asset manager (depending on configuration)
     * In any twig file, you can register a template script file doing: {{ registerTemplateScript($sTemplateScriptFileName) }}
     * @param string $sTemplateScriptFileName name of the script file to publish in general script directory (it should contains the subdirectories)
     * @param string $position
     * @param array $htmlOptions
     */
    public static function registerTemplateScript($sTemplateScriptFileName, $position = null, array $htmlOptions = array())
    {
        $oTemplate = self::getTemplateForRessource($sTemplateScriptFileName);
        Yii::app()->getClientScript()->packages[$oTemplate->sPackageName]['js'][] = $sTemplateScriptFileName;
    }

    /**
     * Publish a script
     * In any twig file, you can register a script doing: {{ registerScript($sId, $sScript) }}
     */
    public static function registerScript($id, $script, $position = null, array $htmlOptions = array())
    {
        $position = self::getPosition($position);
        Yii::app()->getClientScript()->registerScript(
            $id,
            $script,
            $position,
            $htmlOptions
        );
    }

    /**
     * Convert a json object to a PHP array (so no troubles with object method in sandbox)
     */
    public static function json_decode($json)
    {
        return (array) json_decode($json);
    }

    /**
     * @param $position
     * @return string
     */
    public static function getPosition($position)
    {
        switch ($position) {
            case "POS_HEAD":
                $position = LSYii_ClientScript::POS_HEAD;
                break;

            case "POS_BEGIN":
                $position = LSYii_ClientScript::POS_BEGIN;
                break;

            case "POS_END":
                $position = LSYii_ClientScript::POS_END;
                break;

            case "POS_POSTSCRIPT":
                $position = LSYii_ClientScript::POS_POSTSCRIPT;
                break;

            default:
                $position = '';
                break;
        }

        return $position;
    }

    /**
     * Retreive the question classes for a given question id
     * Use in survey template question.twig file.
     * TODO: we'd rather provide a oQuestion object to the twig view with a method getAllQuestion(). But for now, this public static function respect the old way of doing
     *
     * @param  int      $iQid the question id
     * @return string   the classes
     */
    public static function getAllQuestionClasses($iQid)
    {

        $lemQuestionInfo = LimeExpressionManager::GetQuestionStatus($iQid);
        $sType           = $lemQuestionInfo['info']['type'];
        $aSGQA           = explode('X', $lemQuestionInfo['sgqa']);
        $iSurveyId       = $aSGQA[0];

        $aQuestionClass  = Question::getQuestionClass($sType);

        /* Add the relevance class */
        if (!$lemQuestionInfo['relevant']) {
            $aQuestionClass .= ' ls-irrelevant';
            $aQuestionClass .= ' ls-hidden';
        }

        /* Can use aQuestionAttributes too */
        if ($lemQuestionInfo['hidden']) {
            $aQuestionClass .= ' ls-hidden-attribute'; /* another string ? */
            $aQuestionClass .= ' ls-hidden';
        }

        $aQuestionAttributes = QuestionAttribute::model()->getQuestionAttributes($iQid);

        //add additional classes
        if (isset($aQuestionAttributes['cssclass']) && $aQuestionAttributes['cssclass'] != "") {
            /* Got to use static expression */
            $emCssClass = trim(LimeExpressionManager::ProcessString($aQuestionAttributes['cssclass'], null, array(), 1, 1, false, false, true)); /* static var is the lmast one ...*/
            if ($emCssClass != "") {
                $aQuestionClass .= " ".Chtml::encode($emCssClass);
            }
        }

        if ($lemQuestionInfo['info']['mandatory'] == 'Y') {
            $aQuestionClass .= ' mandatory';
        }

        if ($lemQuestionInfo['anyUnanswered'] && $_SESSION['survey_'.$iSurveyId]['maxstep'] != $_SESSION['survey_'.$iSurveyId]['step']) {
            $aQuestionClass .= ' missing';
        }

        return $aQuestionClass;
    }

    public static function renderCaptcha()
    {
        return App()->getController()->createWidget('LSCaptcha', array(
            'captchaAction'=>'captcha',
            'buttonOptions'=>array('class'=> 'btn btn-xs btn-info'),
            'buttonType' => 'button',
            'buttonLabel' => gt('Reload image', 'unescaped')
        ));
    }


    public static function createUrl($url, $params = array())
    {
        return App()->getController()->createUrl($url, $params);
    }

    /**
     * @param string $sRessource
     */
    public static function assetPublish($sRessource)
    {
        return App()->getAssetManager()->publish($sRessource);
    }

    /**
     * @var $sImagePath  string                 the image path relative to the template root
     * @var $alt         string                 the alternative text display
     * @var $htmlOptions array                  additional HTML attribute
     * @return string
     */
    public static function image($sImagePath, $alt = '', $htmlOptions = array( ))
    {
        // Reccurence on templates to find the file
        $oTemplate = self::getTemplateForRessource($sImagePath);

        if ($oTemplate) {
            $sUrlImgAsset = self::assetPublish($oTemplate->path.$sImagePath);
        } else {
            $sUrlImgAsset = '';
            // TODO: publish a default image "not found"
        }

        return CHtml::image($sUrlImgAsset, $alt, $htmlOptions);
    }

    /**
     * @var $sImagePath  string                 the image path relative to the template root
     * @var $default     string                 an alternative image if the provided one cant be found
     * @return string
     */
    /* @TODO => implement the default in a secure way */
    public static function imageSrc($sImagePath, $default = './files/pattern.png')
    {
        // Reccurence on templates to find the file
        $oTemplate = self::getTemplateForRessource($sImagePath);
        $sUrlImgAsset = '';

        if ($oTemplate) {
            $sUrlImgAsset = self::assetPublish($oTemplate->path.$sImagePath);
        } else {
            // TODO: publish a default image "not found"
        }

        return $sUrlImgAsset;
    }

    /**
     * @param string $sRessource
     */
    public static function getTemplateForRessource($sRessource)
    {
        $oRTemplate = Template::getInstance();

        while (!file_exists($oRTemplate->path.$sRessource)) {

            $oMotherTemplate = $oRTemplate->oMotherTemplate;
            if (!($oMotherTemplate instanceof TemplateConfiguration)) {
                return false;
                break;
            }
            $oRTemplate = $oMotherTemplate;
        }

        return $oRTemplate;
    }

    public static function getPost($sName, $sDefaultValue = null)
    {
        return Yii::app()->request->getPost($sName, $sDefaultValue);
    }

    public static function getParam($sName, $sDefaultValue = null)
    {
        return Yii::app()->request->getParam($sName, $sDefaultValue);
    }

    public static function getQuery($sName, $sDefaultValue = null)
    {
        return Yii::app()->request->getQuery($sName, $sDefaultValue);
    }

    /**
     * @param string $name
     */
    public static function unregisterPackage($name)
    {
        return Yii::app()->getClientScript()->unregisterPackage($name);
    }

    /**
     * @param string $name
     */
    public static function unregisterScriptFile($name)
    {
        return Yii::app()->getClientScript()->unregisterScriptFile($name);
    }

    public static function registerScriptFile($path, $position = null)
    {

        Yii::app()->getClientScript()->registerScriptFile($path, ($position === null ? CClientScript::POS_BEGIN : $position));
    }

    public static function registerCssFile($path)
    {
        Yii::app()->getClientScript()->registerCssFile($path);
    }

    public static function registerPackage($name)
    {
        Yii::app()->getClientScript()->registerPackage($name, CClientScript::POS_BEGIN);
    }

    /**
     * Unregister all packages/script files for AJAX rendering
     */
    public static function unregisterScriptForAjax()
    {
        $oTemplate            = Template::getInstance();
        $sTemplatePackageName = 'limesurvey-'.$oTemplate->sTemplateName;
        self::unregisterPackage($sTemplatePackageName);
        self::unregisterPackage('template-core');
        self::unregisterPackage('bootstrap');
        self::unregisterPackage('jquery');
        self::unregisterPackage('bootstrap-template');
        self::unregisterPackage('fontawesome');
        self::unregisterPackage('template-default-ltr');
        self::unregisterPackage('decimal');
        self::unregisterScriptFile('/assets/scripts/survey_runtime.js');
        self::unregisterScriptFile('/assets/scripts/admin/expression.js');
        self::unregisterScriptFile('/assets/scripts/nojs.js');
        self::unregisterScriptFile('/assets/scripts/expressions/em_javascript.js');
    }

    public static function listCoreScripts()
    {
        foreach (Yii::app()->getClientScript()->coreScripts as $key => $package) {

            echo "<hr>";
            echo "$key: <br>";
            var_dump($package);

        }
    }

    public static function listScriptFiles()
    {
        foreach (Yii::app()->getClientScript()->getScriptFiles() as $key => $file) {

            echo "<hr>";
            echo "$key: <br>";
            var_dump($file);

        }
    }


}

<?php

/**
 * This is the model class for table "{{boxes}}".
 *
 * The followings are the available columns in table '{{boxes}}':
 * @property integer $id Primary key
 * @property integer $position
 * @property string $url
 * @property string $title
 * @property string $desc Description
 * @property string $page
 * @property integer $usergroup UserGroup ID
 */
class Box extends CActiveRecord
{
    /** @inheritdoc */
    public function tableName()
    {
        return '{{boxes}}';
    }

    /** @inheritdoc */
    public function rules()
    {
        // NOTE: you should only define rules for those attributes that
        // will receive user inputs.
        return array(
            array('url, title, ico, desc, page', 'required'),
            array('position', 'numerical', 'integerOnly'=>true),
            array('usergroup', 'numerical', 'integerOnly'=>true, 'min'=>-3),
            // The following rule is used by search().
            // @todo Please remove those attributes that should not be searched.
            array('id, position, url, title, ico, desc, page, usergroup', 'safe', 'on'=>'search'),
        );
    }

    /** @inheritdoc */
    public function relations()
    {
        // NOTE: you may need to adjust the relation name and the related
        // class name for the relations automatically generated below.
        return array(
        );
    }

    /** @inheritdoc */
    public function attributeLabels()
    {
        return array(
            'id' => 'ID',
            'position' => gT('Position:'),
            'url' => gT('Destination URL:'),
            'title' => gT('Title:'),
            'ico' => gT('Icon:'),
            'desc' => gT('Description:'),
            'page' => gT('Name of the page where the box should be shown'),
            'usergroup'=> gT('Display this box to:')
        );
    }

    /**
     * Retrieves a list of models based on the current search/filter conditions.
     *
     * Typical usecase:
     * - Initialize the model fields with values from filter form.
     * - Execute this method to get CActiveDataProvider instance which will filter
     * models according to data in model fields.
     * - Pass data provider to CGridView, CListView or any similar widget.
     *
     * @return CActiveDataProvider the data provider that can return the models
     * based on the search/filter conditions.
     */
    public function search()
    {
        // @todo Please modify the following code to remove attributes that should not be searched.

        $criteria = new CDbCriteria;

        $criteria->compare('id', $this->id);
        $criteria->compare('position', $this->position);
        $criteria->compare('url', $this->url, true);
        $criteria->compare('title', $this->title, true);
        $criteria->compare('ico', $this->ico, true);
        $criteria->compare('desc', $this->desc, true);
        $criteria->compare('page', $this->page, true);

        return new CActiveDataProvider($this, array(
            'criteria'=>$criteria,
        ));
    }

    /**
     * @return string
     */
    public function getSpanIcon()
    {
        $spanicon = '<span class="icon-'.$this->ico.' text-success"></span>';
        return $spanicon;
    }

    /**
     * @return mixed|string
     */
    public function getUsergroupname()
    {
        $usergroupid = $this->usergroup;

        // Can't use switch because of empty case
        if (empty($usergroupid) || $usergroupid == '-2') {
            return gT('Only Superadmin');
        } elseif ($usergroupid == '-1') {
            return gT('Everybody');
        } elseif ($usergroupid == '-3') {
            return gT('Nobody');
        } else {
            $oUsergroup = UserGroup::model()->findByPk($usergroupid);

            // The group doesn't exist anymore
            if (!is_object($oUsergroup)) {
                            return gT("Can't find user group!");
            }

            return $oUsergroup->name;
        }
    }

    /**
     * @return string
     */
    public function getbuttons()
    {

        $url = Yii::app()->createUrl("/admin/homepagesettings/sa/update/id/");
        $url .= '/'.$this->id;
        $button = '<a class="btn btn-default" href="'.$url.'" role="button"><span class="fa fa-pencil" ></span></a>';

        $url = Yii::app()->createUrl("/admin/homepagesettings/sa/delete/id/");
        $url .= '/'.$this->id;
        $button .= '<a class="btn btn-default" href="'.$url.'" role="button" data-confirm="'.gT('Are you sure you want to delete this box ?').'"><span class="text-danger fa fa-trash" ></span></a>';
        return $button;
    }

    /**
     * List of all icons available for user
     * Command to generate this list: grep -oh "icon-[a-z]*" styles/Sea_Green/css/fonts.css | sort -u > ~/my_icon_list.txt
     * @return string[]
     */
    public function getIcons()
    {
        return array(
            'icon-active',
            'icon-add',
            'icon-assessments',
            'icon-browse',
            'icon-conditions',
            'icon-copy',
            'icon-cpdb',
            'icon-databack',
            'icon-databegin',
            'icon-dataend',
            'icon-dataforward',
            'icon-defaultanswers',
            'icon-do',
            'icon-edit',
            'icon-emailtemplates',
            'icon-expired',
            'icon-export',
            'icon-exportcsv',
            'icon-exportr',
            'icon-exportspss',
            'icon-exportvv',
            'icon-expression',
            'icon-expressionmanagercheck',
            'icon-global',
            'icon-import',
            'icon-importcsv',
            'icon-importldap',
            'icon-importvv',
            'icon-inactive',
            'icon-invite',
            'icon-label',
            'icon-labels',
            'icon-list',
            'icon-logout',
            'icon-maximize',
            'icon-minimize',
            'icon-organize',
            'icon-quota',
            'icon-remind',
            'icon-renumber',
            'icon-resetsurveylogic',
            'icon-responses',
            'icon-saved',
            'icon-security',
            'icon-settings',
            'icon-shield',
            'icon-superadmin',
            'icon-survey',
            'icon-takeownership',
            'icon-template',
            'icon-templatepermissions',
            'icon-templates',
            'icon-tools',
            'icon-user',
            'icon-usergroup',
            'icon-viewlast'
        );
    }

    /**
     * @return int
     */
    public function getIcons_length()
    {
        return count($this->icons);
    }

    /**
     * @inheritdoc
     * @return Box the static model class
     */
    public static function model($className = __CLASS__)
    {
        /** @var self $model */
        $model = parent::model($className);
        return $model;
    }
}

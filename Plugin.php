<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

use Typecho\Widget\Helper\Form\Element;
use Typecho\Widget\Helper\Layout;

/**
 * Typecho 数据卫士
 *
 * @package DataGuard
 * @author Jcean
 * @version 1.0.3
 * @link https://www.jcean.com
 */
class DataGuard_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return void
     */
    public static function activate() {
        Typecho_Plugin::factory('Widget_Archive')->afterRender = ['DataGuard_Plugin', 'render'];
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     */
    public static function deactivate() {}

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     * @throws Typecho_Exception
     */
    public static function config(Typecho_Widget_Helper_Form $form) {
        if (isset($_GET['action']) && $_GET['action'] == 'backup') {
            $message = self::backup($_GET['type'], $_GET['name']);
            Typecho_Widget::widget('Widget_Notice')->set(_t($message['msg']), $message['status']);
            Utils::getResponseInstance->goBack();
        }
        if (isset($_GET['action']) && $_GET['action'] == 'restore') {
            self::restore($_GET['type'], $_GET['name']);
            $message = "恢复成功!";
            Typecho_Widget::widget('Widget_Notice')->set(_t($message), 'success');
            Utils::getResponseInstance->goBack();
        }
        if (isset($_GET['action']) && $_GET['action'] == 'delete') {
            self::delete($_GET['type'], $_GET['name']);
            $message = "删除成功!";
            Typecho_Widget::widget('Widget_Notice')->set(_t($message), 'success');
            Utils::getResponseInstance->goBack();
        }

        $actionUrl = Typecho_Common::url('/options-plugin.php?config=DataGuard&action=%s&type=%s&name=%s', Helper::options()->adminUrl);

        $backups = [
            'theme' => [],
            'plugin' => []
        ];

        Typecho_Widget::widget('Widget_Themes_List')->to($themes);
        while($themes->next())
            if($themes->activated)
                array_push($backups['theme'], $themes->name);
        $plugins = Typecho_Plugin::export()['activated'];
        foreach ($plugins as $plugin => $v)
            if($plugin != 'DataGuard')
                array_push($backups['plugin'], $plugin);

        foreach ($backups as $type => $v) {
            $backupTitle[$type] = new DataGuard_Title_Plugin('backupTitle', null, null, self::getText($type)._t("备份"), null);
            $form->addItem($backupTitle[$type]);
            if(sizeof($v) <= 0) {
                $backupTitle[$type]->message(_t("暂无需备份") . _t($type));
            }

            for ($i = 0; $i < sizeof($v); $i++) {
                $name = $v[$i];
                if($name === 'DataGuard') continue;
                $backupTime = self::loadBackupTime($type, $name);
                $themeTitle = new DataGuard_SubTitle_Plugin('SubTitle', null, null, _t(($i+1) . ". {$name}"), _t('上次备份时间'));
                $themeTitle->description(_t($backupTime));
                $form->addItem($themeTitle);
                $btnArr = [
                    'backup' => [
                        'color' => '#5cb85c',
                        'text' => '立即备份'
                    ],
                    'restore' => [
                        'color' => '#f0ad4e',
                        'text' => '恢复备份'
                    ],
                    'delete' => [
                        'color' => '#d9534f',
                        'text' => '删除备份'
                    ]
                ];
                $btnBox = new Typecho_Widget_Helper_Layout('div', [
                    'style' => 'height: 3em;margin-bottom: 3em;'
                ]);
                $form->addItem($btnBox);
                foreach ($btnArr as $operateType => $attr) {
                    $operateBtn = new Typecho_Widget_Helper_Form_Element_Submit();
                    $operateBtn->value(_t($attr['text']));
                    $operateBtn->input->setAttribute('style', "background: {$attr['color']};");
                    $operateBtn->input->setAttribute('class', 'btn btn-s btn-operate');
                    $operateBtn->input->setAttribute('onclick', 'javascript:return btnClick(this)');
                    $operateBtn->input->setAttribute('formaction', sprintf($actionUrl, $operateType, $type, $name));
                    $btnBox->addItem($operateBtn);
                }
            }
        }

        $form->addItem(new DataGuard_Title_Plugin('autoSaveTitle', null, null, _t('自动备份'), null));
        $cycle = new Typecho_Widget_Helper_Form_Element_Text('cycle', null, '0', _t('保存周期(天)'), _t('留空或置0取消自动更新'));
        $cycle->input->setAttribute('class', 'mini');
        $cycle->addRule('isInteger', _t('更新周期必须是纯数字'));
        $form->addInput($cycle);

        echo <<<HTML
            <style>
            .btn-s {
            float: left;
                width: 10em;
                height: 3em;
                line-height: 3em;
                margin: 0 1em 1em 0 !important;
                border: none;
                color: #fff;
                font-size: 1em;
                border-radius: 2em;
                transition: all 0.35s;
            }
            </style>
HTML;

        echo <<<JAVASCRIPT
            <script type="text/javascript">
            function btnClick(element) {
                return confirm("确定要" + element.innerText + "吗?")
            }
            </script>
JAVASCRIPT;
    }

    /**
     * 个人用户的配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    /**
     * 插件实现方法
     *
     * @access public
     * @param $archive
     * @return void
     * @throws Typecho_Db_Exception
     * @throws Typecho_Exception
     * @throws Typecho_Plugin_Exception
     */
    public static function render($archive) {
        $configs = Helper::options()->plugin('DataGuard');
        $cycle = $configs->cycle;
        if(!empty($cycle)) {
            $backupTimeName = 'plugin:DataGuard:b:t';
            $db = Typecho_Db::get();
            $widget = Typecho_Widget::widget('Widget_Abstract_Options');
            $backupTimeRow = $db->fetchRow($widget->select()->where('name = ?', $backupTimeName)->where('user = 0'));
            $currentTime = time();
            $hasBackupTime = false;
            if(!empty($backupTimeRow)) {
                $hasBackupTime = true;
                $backupTime = intval($backupTimeRow['value']);
                if(($currentTime - $backupTime) < $cycle * 24 * 60 * 60) {
                    return $archive;
                }
            }
            Typecho_Widget::widget('Widget_Themes_List')->to($themes);
            while($themes->next()) {
                if($themes->activated) {
                    self::backup('theme', $themes->name);
                }
            }
            $plugins = Typecho_Plugin::export()['activated'];
            foreach ($plugins as $plugin =>  $v) {
                if ($plugin != 'DataGuard') {
                    self::backup('plugin', $plugin);
                }
            }
            if($hasBackupTime) {
                $backupTime = [
                    'value' => $currentTime
                ];
                $widget->update($backupTime, $db->sql()->where('name = ?', $backupTimeName)->where('user = 0'));
            } else {
                $backupTime = [
                    'name' => $backupTimeName,
                    'user' => 0,
                    'value' => $currentTime
                ];
                $widget->insert($backupTime);
            }
        }
    }

    private static function loadBackupTime($type, $name) {
        $name = "{$type}:{$name}";
        $backupName = "{$name}:b";
        $backupTimeName = "{$backupName}:t";
        $db = Typecho_Db::get();
        $widget = Typecho_Widget::widget('Widget_Abstract_Options');

        $backupTimeRow = $db->fetchRow($widget->select()->where('name = ?', $backupTimeName)->where('user = 0'));
        $color = "#2299dd";
        $backupTime = _t("从未备份");
        $hasBackupTime = false;
        if (!empty($backupTimeRow) && intval($backupTimeRow['value']) > 0) {
            $date = new Typecho_Date(intval($backupTimeRow['value']));
            $color = "#0c6";
            $backupTime = $date->format('Y-m-d H:i:s');
            $hasBackupTime = true;
        }

        if ($hasBackupTime) {
            $backupRow = $db->fetchRow($widget->select()->where('name = ?', $backupName)->where('user = 0'));
            if (empty($backupRow) || empty($backupRow['value'])) {
                $color = "#F55852";
                $backupTime = _t("上次备份不完整, 请重新执行备份!");
            }
        }

        return "<span style=\"font-weight: bold;color: ${color}\">" . _t("上次备份时间") . ": {$backupTime}</span>";
    }

    private static function backup($type, $name) {
        $name = "{$type}:{$name}";
        $backupName = "{$name}:b";
        $backupTimeName = "{$backupName}:t";
        $backupTimeNameLen = strlen($backupTimeName);

        $db = Typecho_Db::get();
        $widget = Typecho_Widget::widget('Widget_Abstract_Options');

        $currentRow = $db->fetchRow($widget->select()->where('name = ?', $name)->where('user = 0'));
        if (!empty($currentRow)) {
            $dbName = $db->getConfig(Typecho_Db::READ)->database;
            $prefix = $db->getPrefix();
            $getNameLenSql = "SELECT `CHARACTER_MAXIMUM_LENGTH` FROM `INFORMATION_SCHEMA`.`COLUMNS` WHERE `table_name` = '{$prefix}options' AND table_schema = '{$dbName}' AND `column_name` = 'name';";
            $getNameLenSql = $db->query($getNameLenSql);
            $optionNameLenRow = $db->fetchRow($getNameLenSql);
            $optionNameLen = $optionNameLenRow['CHARACTER_MAXIMUM_LENGTH'];

            if($optionNameLen < $backupTimeNameLen) {
                $setNameLenSql = "ALTER TABLE `{$prefix}options` MODIFY `name` varchar({$backupTimeNameLen})";
                $db->query($setNameLenSql);
            }

            $backupRow = $widget->size($db->sql()->where('name = ?', $backupName)->where('user = 0')) > 0;
            if ($backupRow) {
                $backup = [
                    'value' => $currentRow['value']
                ];
                $widget->update($backup, $db->sql()->where('name = ?', $backupName)->where('user = 0'));
            } else {
                $backup = [
                    'name' => $backupName,
                    'user' => 0,
                    'value' => $currentRow['value']
                ];
                $widget->insert($backup);
            }
            $backupTime = $widget->size($db->sql()->where('name = ?', $backupTimeName)->where('user = 0')) > 0;
            $currentTime = time();
            if ($backupTime) {
                $backup = [
                    'value' => $currentTime
                ];
                $widget->update($backup, $db->sql()->where('name = ?', $backupTimeName)->where('user = 0'));
            } else {
                $backup = [
                    'name' => $backupTimeName,
                    'user' => 0,
                    'value' => $currentTime
                ];
                $widget->insert($backup);
            }
            return [
                'msg' => "备份成功!",
                'status' => 'success'
            ];
        } else
            return [
                'msg' => "未找到 {$name} 配置数据!",
                'status' => 'error'
            ];
    }

    private static function restore($type, $name) {
        $name = "{$type}:{$name}";
        $backupName = "{$name}:b";

        $db = Typecho_Db::get();
        $widget = Typecho_Widget::widget('Widget_Abstract_Options');
        $backupRow = $db->fetchRow($widget->select()->where('name = ?', $backupName)->where('user = 0'));
        if (empty($backupRow) || empty($backupRow['value'])) {
            $message = "备份恢复终止: 未找到 {$name} 配置备份数据!";
            Typecho_Widget::widget('Widget_Notice')->set(_t($message), 'error');
            Utils::getResponseInstance->goBack();
            return;
        }

        $currentRow = $widget->size($db->sql()->where('name = ?', $name)->where('user = 0')) > 0;
        if ($currentRow) {
            $backup = [
                'value' => $backupRow['value']
            ];
            $widget->update($backup, $db->sql()->where('name = ?', $name)->where('user = 0'));
        } else {
            $backup = [
                'name' => $name,
                'user' => 0,
                'value' => $backupRow['value']
            ];
            $widget->insert($backup);
        }
    }

    private static function delete($type, $name) {
        $name = "{$type}:{$name}";
        $backupName = "{$name}:b";
        $backupTimeName = "{$backupName}:t";

        $widget = Typecho_Widget::widget('Widget_Abstract_Options');
        $widget->delete($widget->select()->where('name = ?', $backupName)->where('user = 0')->orWhere('name = ?', $backupTimeName)->where('user = 0'));
        $message = "删除成功!";
        Typecho_Widget::widget('Widget_Notice')->set(_t($message), 'success');
        $responseObject->goBack();
    }

    private static function getText($type) {
        return $type==='theme'?'主题':'插件';
    }
}


class DataGuard_Title_Plugin extends Typecho_Widget_Helper_Form_Element
{
    public function value($value): Element {
        return parent::value($value);
    }

    public function label(string $value): Element {
        /** 创建标题元素 */
        if (empty($this->label)) {
            $this->label = new Typecho_Widget_Helper_Layout('label', ['class' => 'typecho-label', 'style'=>'font-size: 2em;border-bottom: 1px #ddd solid;']);
            $this->container($this->label);
        }

        $this->label->html($value);
        return $this;
    }

    public function input(?string $name = NULL, ?array $options = NULL): ?Layout {
        $input = new Typecho_Widget_Helper_Layout('p', array());
        $this->container($input);
        $this->inputs[] = $input;
        return $input;
    }

    public function message(string $message): Element {
        if (empty($this->message)) {
            $this->message =  new Typecho_Widget_Helper_Layout('p', ['class' => 'message notice']);
            $this->container($this->message);
        }

        $this->message->html($message);
        return $this;
    }

    protected function _value($value) {}
    
    protected function inputValue($value) {}
}

class DataGuard_SubTitle_Plugin extends DataGuard_Title_Plugin
{
    public function label(string $value): Element {
        /** 创建标题元素 */
        if (empty($this->label)) {
            $this->label = new Typecho_Widget_Helper_Layout('label', ['class' => 'typecho-label', 'style'=>'font-size: 1.5em;']);
            $this->container($this->label);
        }

        $this->label->html($value);
        return $this;
    }
}

class Utils {
    public static function getResponseInstance() {
        if (class_exists('\Typecho\Widget\Response') && class_exists('\Typecho\Request') && class_exists('\Typecho\Response')) {
            return new Typecho_Widget_Response(Typecho_Request::getInstance(), Typecho_Response::getInstance());
        }
        return Typecho_Response::getInstance();
    }
}
<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * Typecho 数据卫士
 * 
 * @package DataGuard
 * @author Jesus0s
 * @version 1.0.0
 * @link https://www.jesus0s.com
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
        Typecho_Plugin::factory('Widget_Archive')->afterRender = array('DataGuard_Plugin', 'render');
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
            $name = "{$_GET['type']}:{$_GET['name']}";
            self::backup($name);
            $message = "备份成功!";
            Typecho_Widget::widget('Widget_Notice')->set(_t($message), 'success');
            Typecho_Response::getInstance()->goBack();
        }
        if (isset($_GET['action']) && $_GET['action'] == 'restore') {
            $name = "{$_GET['type']}:{$_GET['name']}";
            self::restore($name);
            $message = "恢复成功!";
            Typecho_Widget::widget('Widget_Notice')->set(_t($message), 'success');
            Typecho_Response::getInstance()->goBack();
        }

        Typecho_Widget::widget('Widget_Themes_List')->to($themes);
        $plugins = Typecho_Plugin::export()['activated'];

        $actionUrl = Typecho_Common::url('/options-plugin.php?config=DataGuard&action=%s&type=%s&name=%s', Helper::options()->adminUrl);

        $form->addInput(new Title_Plugin('themeTitle', null, null, _t('主题备份'), null));

        $i = 0;
        while($themes->next()) {
            if($themes->activated) {
                $name = "theme:{$themes->name}";
                $backupName = "{$name}:b";
                $backupTime = self::loadBackupTime($backupName);
                $themeTitle = new SubTitle_Plugin('SubTitle', null, null, _t((++$i) . ". {$themes->title}"), _t('上次备份时间'));
                $themeTitle->description(_t($backupTime));
                $form->addInput($themeTitle);
                $backupBtn = new Typecho_Widget_Helper_Form_Element_Submit();
                $backupBtn->value(_t('立即备份'));
                $backupBtn->input->setAttribute('class', 'btn btn-s primary btn-operate');
                $backupBtn->input->setAttribute('onclick', 'javascript:return btnClick(this)');
                $backupBtn->input->setAttribute('formaction', sprintf($actionUrl, 'backup', 'theme', $themes->name));
                $form->addItem($backupBtn);
                $restoreBtn = new Typecho_Widget_Helper_Form_Element_Submit();
                $restoreBtn->value(_t('恢复备份'));
                $restoreBtn->input->setAttribute('class', 'btn btn-s btn-warn btn-operate');
                $restoreBtn->input->setAttribute('onclick', 'javascript:return btnClick(this)');
                $restoreBtn->input->setAttribute('formaction', sprintf($actionUrl, 'restore', 'theme', $themes->name));
                $form->addItem($restoreBtn);
            }
        }

        $form->addInput(new Title_Plugin('pluginTitle', null, null, _t('插件备份'), null));

        $i = 0;
        foreach ($plugins as $plugin =>  $v) {
            if($plugins != 'DataGuard') {
                $name = "plugin:{$plugin}";
                $backupName = "{$name}:b";
                $backupTime = self::loadBackupTime($backupName);
                $pluginTitle = new SubTitle_Plugin('SubTitle',null, null, _t((++$i) . ". {$plugin}"), _t('上次备份时间'));
                $pluginTitle->description(_t($backupTime));
                $form->addInput($pluginTitle);
                $backupBtn = new Typecho_Widget_Helper_Form_Element_Submit();
                $backupBtn->value(_t('立即备份'));
                $backupBtn->input->setAttribute('class','btn btn-s primary btn-operate');
                $backupBtn->input->setAttribute('onclick','javascript:return btnClick(this)');
                $backupBtn->input->setAttribute('formaction', sprintf($actionUrl, 'backup', 'plugin', $plugin));
                $form->addItem($backupBtn);
                $restoreBtn = new Typecho_Widget_Helper_Form_Element_Submit();
                $restoreBtn->value(_t('恢复备份'));
                $restoreBtn->input->setAttribute('class','btn btn-s btn-warn btn-operate');
                $restoreBtn->input->setAttribute('onclick','javascript:return btnClick(this)');
                $restoreBtn->input->setAttribute('formaction', sprintf($actionUrl, 'restore', 'plugin', $plugin));
                $form->addItem($restoreBtn);
            }
        }

        $form->addInput(new Title_Plugin('autoSaveTitle', null, null, _t('自动备份'), null));
        $cycle = new Typecho_Widget_Helper_Form_Element_Text('cycle', null, '0', _t('保存周期(天)'), _t('留空或置0取消自动更新'));
        $cycle->input->setAttribute('class', 'mini');
        $cycle->addRule('isInteger', _t('更新周期必须是纯数字'));
        $form->addInput($cycle);

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
            $plugins = Typecho_Plugin::export()['activated'];
            while($themes->next()) {
                if($themes->activated) {
                    self::backup("theme:{$themes->name}");
                }
            }
            foreach ($plugins as $plugin =>  $v) {
                if ($plugin != 'DataGuard') {
                    self::backup("plugin:{$plugin}");
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

    private static function loadBackupTime($backupName) {
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

    private static function backup($name) {
        $backupName = "{$name}:b";
        $backupTimeName = "{$backupName}:t";

        $db = Typecho_Db::get();
        $widget = Typecho_Widget::widget('Widget_Abstract_Options');
        $currentRow = $db->fetchRow($widget->select()->where('name = ?', $name)->where('user = 0'));
        if (!empty($currentRow)) {
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
        }

        $backupTime = $widget->size($db->sql()->where('name = ?', $backupTimeName)->where('user = 0')) > 0;
        $currentTime = time();
        if ($backupTime) {
            $backup = [
                'value' => $currentTime
            ];
            $widget->update($backup, $db->sql()->where('name = ?', $backupTimeName)->where('user = 0'));
        } else {
            $backup = array(
                'name' => $backupTimeName,
                'user' => 0,
                'value' => $currentTime
            );
            $widget->insert($backup);
        }
    }

    private static function restore($name) {
        $backupName = "{$name}:b";

        $db = Typecho_Db::get();
        $widget = Typecho_Widget::widget('Widget_Abstract_Options');
        $backupRow = $db->fetchRow($widget->select()->where('name = ?', $backupName)->where('user = 0'));
        if (empty($backupRow) || empty($backupRow['value'])) {
            $message = "备份恢复终止: 未找到 {$name} 配置备份数据!";
            Typecho_Widget::widget('Widget_Notice')->set(_t($message), 'error');
            Typecho_Response::getInstance()->goBack();
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
}


class Title_Plugin extends Typecho_Widget_Helper_Form_Element
{
    public function label($value)
    {
        /** 创建标题元素 */
        if (empty($this->label)) {
            $this->label = new Typecho_Widget_Helper_Layout('label', array('class' => 'typecho-label', 'style'=>'font-size: 2em;border-bottom: 1px #ddd solid;padding-top:2em;'));
            $this->container($this->label);
        }

        $this->label->html($value);
        return $this;
    }

    public function input($name = NULL, array $options = NULL)
    {
        $input = new Typecho_Widget_Helper_Layout('p', array());
        $this->container($input);
        $this->inputs[] = $input;
        return $input;
    }
    protected function _value($value) {}
}

class SubTitle_Plugin extends Typecho_Widget_Helper_Form_Element
{
    public function label($value)
    {
        /** 创建副标题元素 */
        if (empty($this->label)) {
            $this->label = new Typecho_Widget_Helper_Layout('label', array('class' => 'typecho-label', 'style'=>'font-size: 1.5em;padding-top:2em;'));
            $this->container($this->label);
        }

        $this->label->html($value);
        return $this;
    }

    public function input($name = NULL, array $options = NULL)
    {
        $input = new Typecho_Widget_Helper_Layout('p', array());
        $this->container($input);
        $this->inputs[] = $input;
        return $input;
    }
    protected function _value($value) {}
}
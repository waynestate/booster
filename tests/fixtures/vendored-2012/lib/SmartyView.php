<?php

class SmartyView extends Slim_View
{
    public function render($template)
    {
        $smarty = new Smarty();
        $smarty->template_dir = $this->getTemplatesDirectory();
        $smarty->assign($this->data);

        return $smarty->fetch($template);
    }
}

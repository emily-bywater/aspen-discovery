<?php


class WebBuilder_Form extends Action{
	private $form;
	function launch()
	{
		global $interface;

		$id = strip_tags($_REQUEST['id']);
		$interface->assign('id', $id);

		require_once ROOT_DIR . '/sys/WebBuilder/CustomForm.php';
		$this->form = new CustomForm();
		$this->form->id = $id;
		if (!$this->form->find(true)){
			$this->display('../Record/invalidPage.tpl', 'Invalid Page');
			die();
		}

		require_once ROOT_DIR . '/sys/Parsedown/AspenParsedown.php';
		$parsedown = AspenParsedown::instance();
		$parsedown->setBreaksEnabled(true);
		$introText = $parsedown->parse($this->form->introText);

		$interface->assign('introText', $introText);
		$interface->assign('contents', $this->form->getFormattedFields());
		$interface->assign('title', $this->form->title);

		$this->display('customForm.tpl', $this->form->title, '', false);
	}

	function getBreadcrumbs()
	{
		$breadcrumbs = [];
		$breadcrumbs[] = new Breadcrumb('/', 'Home');
		$breadcrumbs[] = new Breadcrumb('', $this->form->title, true);
		if (UserAccount::userHasPermission(['Administer All Custom Forms', 'Administer Library Custom Forms'])){
			$breadcrumbs[] = new Breadcrumb('/WebBuilder/CustomForms?id=' . $this->form->id . '&objectAction=edit', 'Edit', true);
		}
		return $breadcrumbs;
	}
}
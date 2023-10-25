<?php
/**
 * @package   panopticon
 * @copyright Copyright (c)2023-2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\Panopticon\Controller;


use Awf\Mvc\Controller;

defined('AKEEBA') || die;

class Extupdates extends Controller
{
	public function main()
	{
		$view      = $this->getView();
		$siteModel = $this->getModel('site');
		$view->setModel('site', $siteModel);

		parent::main();
	}

}
<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.form.article
 * @copyright   Copyright (C) 2005-2016  Media A-Team, Inc. - All rights reserved.
 * @license     GNU/GPL http://www.gnu.org/copyleft/gpl.html
 */

// No direct access
defined('_JEXEC') or die('Restricted access');

use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Filesystem\Path;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Table\Table;
use Joomla\Component\Content\Site\Model\ArticleModel;
use Fabrik\Component\Fabrik\Site\Plugin\AbstractFormPlugin;
use Joomla\String\Normalise;
use Joomla\Utilities\ArrayHelper;
use Joomla\String\StringHelper;
use Fabrik\Helpers\ArrayHelper as FArrayHelper;
use Fabrik\Helpers\StringHelper as FStringHelper;
use Fabrik\Helpers\Worker;

/**
 * Create Joomla article(s) upon form submission
 *
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.form.article
 * @since       3.0
 */
class PlgFabrik_FormArticle extends AbstractFormPlugin
{
	/**
	 * Images
	 *
	 * @var object
	 *
	 * @since 4.0
	 */
	public $images = null;

	/**
	 * Create articles - needed to be before store as we are altering the meta-store element's data
	 *
	 * @return    bool
	 *
	 * @since 4.0
	 */
	public function onAfterProcess()
	{
		$formModel  = $this->getModel();
		$params     = $this->getParams();
		$this->data = $this->getProcessData();

		// We need this for $formModel->getElementData() to work
		$formModel->formData = $this->data;

		if (!$this->shouldProcess('article_conditon', $this->data, $params))
		{
			return;
		}

		$store = $this->metaStore();

		if ($catElement = $formModel->getElement($params->get('categories_element'), true))
		{
			$cat        = $catElement->getFullName() . '_raw';
			$categories = (array) FArrayHelper::getValue($this->data, $cat);
			$this->mapCategoryChanges($categories, $store);
		}
		else
		{
			$categories = (array) $params->get('categories');
		}

		foreach ($categories as $category)
		{
			$id               = isset($store->$category) ? $store->$category : null;
			$item             = $this->saveArticle($id, $category);
			$store->$category = $item->id;
		}

		$this->setMetaStore($store);

		return true;
	}

	/**
	 * If changing selected category on editing a record, the new category id needs to be assigned as a
	 * property to $store with the existing article id. Not tested if say for example the category element
	 * is a multi-select
	 *
	 * @param   array   $categories Categories selected by the user
	 * @param   object &$store      Previously stored categoryid->articleid map
	 *
	 * @return  object  $store
	 *
	 * @since 4.0
	 */
	protected function mapCategoryChanges($categories, &$store)
	{
		$defaultArticleId = null;

		if (!empty($categories))
		{
			foreach ($store as $catId => $articleId)
			{
				if (!in_array($catId, $categories))
				{
					// We've swapped categories for an existing article
					$defaultArticleId = $articleId;
				}
			}

			foreach ($categories as $category)
			{
				if (!isset($store->$category))
				{
					if ($category !== '')
					{
						$store->$category = $defaultArticleId;
					}
				}
			}
		}

		return $store;
	}

	/**
	 * Save article
	 *
	 * @param   int $id    Article Id
	 * @param   int $catId Category Id
	 *
	 * @return Table
	 */
	protected function saveArticle($id, $catId)
	{
		// Include the content plugins for the on save events.
		PluginHelper::importPlugin('content');

		$params     = $this->getParams();
		$data       = array('articletext' => $this->buildContent(), 'catid' => $catId, 'state' => 1, 'language' => '*');
		$attributes = array(
			'title'        => '',
			'publish_up'   => '',
			'publish_down' => '',
			'featured'     => '0',
			'state'        => '1',
			'metadesc'     => '',
			'metakey'      => '',
			'tags'         => '',
			'alias'        => '',
			'ordering'     => ''
		);

		$data['images']   = json_encode($this->images());
		$data['language'] = $this->getLang();
		$data['access']   = $this->getLevel();

		$isNew = is_null($id) ? true : false;

		if ($isNew)
		{
			$data['created']          = $this->date->toSql();
			$attributes['created_by'] = $this->user->get('id');
		}
		else
		{
			$data['modified']    = $this->date->toSql();
			$data['modified_by'] = $this->user->get('id');
		}

		foreach ($attributes as $attribute => $default)
		{
			$elementId        = (int) $params->get($attribute);
			$data[$attribute] = $this->findElementData($elementId, $default);
		}

		$data['tags'] = (array) $data['tags'];

		$this->generateNewTitle($id, $catId, $data);

		if (!$isNew)
		{
			$readMore            = 'index.php?option=com_content&view=article&id=' . $id;
			$data['articletext'] = str_replace('{readmore}', $readMore, $data['articletext']);
		}

		$item = Table::getInstance('Content');
		$item->load($id);
		$item->bind($data);

		// Trigger the onContentBeforeSave event.
		Factory::getApplication()->triggerEvent('onContentBeforeSave', array('com_content.article', $item, $isNew));

		$item->store();

		/**
		 * Featured is handled by the admin content model, when you are saving in ADMIN
		 * Otherwise we've had to hack over the admin featured() method into this plugin for the front end
		 */

		Table::addIncludePath(COM_FABRIK_BASE . 'administrator/components/com_content/tables');

		if ($this->app->isClient('administrator'))
		{
			BaseDatabaseModel::addIncludePath(COM_FABRIK_BASE . 'administrator/components/com_content/models');
			$articleModel = BaseDatabaseModel::getInstance('Article', 'ContentModel');
			$articleModel->featured($item->id, $item->featured);
		}
		else
		{
			$this->featured($item->id, $item->featured);
		}

		// Trigger the onContentAfterSave event.
		Factory::getApplication()->triggerEvent('onContentAfterSave', array('com_content.article', $item, $isNew));

		// New record - need to re-save with {readmore} replacement
		if ($isNew && strstr($data['articletext'], '{readmore}'))
		{
			$readMore            = 'index.php?option=com_content&view=article&id=' . $item->id;
			$data['articletext'] = str_replace('{readmore}', $readMore, $data['articletext']);
			$item->bind($data);
			$item->store();
		}

		if (!$isNew)
		{
			$cache = Factory::getCache('com_content');
			$cache->clean($id);
		}

		return $item;
	}

	/**
	 * Copied from admin content model
	 * Method to toggle the featured setting of articles.
	 *
	 * @param   array    The ids of the items to toggle.
	 * @param   integer  The value to toggle to.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since 4.0
	 */
	public function featured($pks, $value = 0)
	{
		// Sanitize the ids.
		$pks = (array) $pks;
		$pks = ArrayHelper::toInteger($pks);
		$db  = $this->db;

		if (empty($pks))
		{
			$this->setError(JText::_('COM_CONTENT_NO_ITEM_SELECTED'));

			return false;
		}

		$table = Table::getInstance('Featured', 'ContentTable');

		try
		{
			$query = $db->getQuery(true)
				->update($db->qn('#__content'))
				->set('featured = ' . (int) $value)
				->where('id IN (' . implode(',', $pks) . ')');
			$db->setQuery($query);
			$db->execute();

			if ((int) $value == 0)
			{
				// Adjust the mapping table.
				// Clear the existing features settings.
				$query = $db->getQuery(true)
					->delete($db->qn('#__content_frontpage'))
					->where('content_id IN (' . implode(',', $pks) . ')');
				$db->setQuery($query);
				$db->execute();
			}
			else
			{
				// first, we find out which of our new featured articles are already featured.
				$query = $db->getQuery(true)
					->select('f.content_id')
					->from('#__content_frontpage AS f')
					->where('content_id IN (' . implode(',', $pks) . ')');
				//echo $query;
				$db->setQuery($query);

				$oldFeatured = $db->loadColumn();

				// we diff the arrays to get a list of the articles that are newly featured
				$newFeatured = array_diff($pks, $oldFeatured);

				// Featuring.
				$tuples = array();
				foreach ($newFeatured as $pk)
				{
					$tuples[] = $pk . ', 0';
				}
				if (count($tuples))
				{
					$columns = array('content_id', 'ordering');
					$query   = $db->getQuery(true)
						->insert($db->qn('#__content_frontpage'))
						->columns($db->qn($columns))
						->values($tuples);
					$db->setQuery($query);
					$db->execute();
				}
			}
		}
		catch (Exception $e)
		{
			$this->setError($e->getMessage());

			return false;
		}

		$table->reorder();

		//$this->cleanCache();

		return true;
	}

	/**
	 * Get the element data from the Fabrik form
	 *
	 * @param   int    $elementId Element id
	 * @param   string $default   Default value
	 *
	 * @return mixed
	 *
	 * @since 4.0
	 */
	protected function findElementData($elementId, $default = '')
	{
		$formModel = $this->getModel();
		$value     = '';

		if ($elementId === 0)
		{
			return $default;
		}

		if ($elementModel = $formModel->getElement($elementId, true))
		{
			$fullName = $elementModel->getFullName(true, false);
			$value    = $formModel->getElementData($fullName, true, $default);

			if (is_array($value) && count($value) === 1)
			{
				$value = array_shift($value);
			}

			// Dates need to have date element tz options applied
			if (is_a($elementModel, 'PlgFabrik_ElementDate'))
			{
				if (is_array($value) && array_key_exists('date', $value))
				{
					$value = $value['date'];
				}

				$value = $elementModel->storeDatabaseFormat($value, array());
			}
		}

		return $value;
	}

	/**
	 * Build the Joomla article image data
	 *
	 * @return \stdClass
	 */
	protected function images()
	{
		if (isset($this->images))
		{
			return $this->images;
		}

		$params = $this->getParams();

		$formModel = $this->getModel();
		$introImg  = $params->get('image_intro', '');
		$fullImg   = $params->get('image_full', '');
		$img       = new \stdClass;

		if ($introImg !== '')
		{
			$size = $params->get('image_intro_size', 'cropped');
			list($file, $placeholder) = $this->setImage($introImg, $size);

			if ($file !== '')
			{
				$img->image_intro         = str_replace('\\', '/', $file);
				$img->image_intro         = FStringHelper::ltrimword($img->image_intro, '/');
				$img->float_intro         = '';
				$img->image_intro_alt     = '';
				$img->image_intro_caption = '';

				$elementModel = $formModel->getElement($introImg, true);

				if (get_class($elementModel) === 'PlgFabrik_ElementFileupload')
				{
					$name       = $elementModel->getFullName(true, false);
					$img->$name = $placeholder;
				}
			}
		}

		if ($fullImg !== '')
		{
			$size = $params->get('image_full_size', 'thumb');
			list($file, $placeholder) = $this->setImage($fullImg, $size);

			if ($file !== '')
			{
				$img->image_fulltext         = str_replace('\\', '/', $file);
				$img->image_fulltext         = FStringHelper::ltrimword($img->image_fulltext, '/');
				$img->float_fulltext         = '';
				$img->image_fulltext_alt     = '';
				$img->image_fulltext_caption = '';

				$elementModel = $formModel->getElement($fullImg, true);

				if (get_class($elementModel) === 'PlgFabrik_ElementFileupload')
				{
					$name       = $elementModel->getFullName(true, false);
					$img->$name = $placeholder;
				}
			}
		}
		// Parse any other fileupload image
		$uploads = $formModel->getListModel()->getElementsOfType('fileupload');

		foreach ($uploads as $upload)
		{
			$name      = $upload->getFullName(true, false);
			$shortName = $upload->getElement()->name;
			$size      = $params->get('image_full_size', 'thumb');

			list($file, $placeholder) = $this->setImage($upload->getElement()->id, $size);

			$img->$name      = $placeholder;
			$img->$shortName = $placeholder;
		}

		$this->images = $img;

		return $this->images;
	}

	/**
	 * Get thumb/cropped/full image paths
	 *
	 * @param   int    $elementId Element id
	 * @param   string $size      Type of file to find (cropped/thumb/full)
	 *
	 * @return  array   ($image, $placeholder)
	 *
	 * @since 4.0
	 */
	protected function setImage($elementId, $size)
	{
		$file = $this->findElementData($elementId);

		if ($file === '')
		{
			return array('', '');
		}

		// Initial upload $file is json data / ajax upload?
		if ($f = json_decode($file))
		{
			if (array_key_exists(0, $f))
			{
				$file = $f[0]->file;
			}
		}

		$formModel = $this->getModel();

		/** @var PlgFabrik_ElementFileupload $elementModel */
		$elementModel = $formModel->getElement($elementId, true);

		if (get_class($elementModel) === 'PlgFabrik_ElementFileupload')
		{
			$name        = $elementModel->getHTMLName();
			$data[$name] = $file;
			$elementModel->setEditable(false);
			$placeholder = $elementModel->render($data);

			$storage = $elementModel->getStorage();
			$file    = $storage->clean(Path_SITE . '/' . $file);
			$file    = $storage->pathToURL($file);

			switch ($size)
			{
				case 'cropped':
					$file = $storage->getCropped($file);
					break;
				case 'thumb':
					$file = $storage->getThumb($file);
					break;
			}

			$file  = $storage->urlToPath($file);
			$file  = str_replace(Path_SITE, '', $file);
			$first = substr($file, 0, 1);

			if ($first === '\\' || $first == '/')
			{
				$file = FStringHelper::ltrimiword($file, $first);
			}
		}

		return array($file, $placeholder);
	}

	/**
	 * Get selected language
	 *
	 * @return string
	 *
	 * @since 4.0
	 */
	protected function getLang()
	{
		$params          = $this->getParams();
		$lang            = '*';
		$languageElement = $params->get('language_element');

		if (empty($languageElement))
		{
			$language = $params->get('language');
			if (!empty($language))
			{
				$lang = $language;
			}
		}
		else
		{
			$language = $this->findElementData($languageElement);
			if (!empty($language))
			{
				$lang = $language;
			}
		}

		return $lang;
	}

	/**
	 * Get selected access level
	 *
	 * @return string
	 *
	 * @since 4.0
	 */
	protected function getLevel()
	{
		$params       = $this->getParams();
		$levelID      = '1';
		$levelElement = $params->get('level_element');

		if (empty($levelElement))
		{
			$level = $params->get('level');
			if (!empty($level))
			{
				$levelID = $level;
			}
		}
		else
		{
			$level = $this->findElementData($levelElement);
			if (!empty($level))
			{
				$levelID = $level;
			}
		}

		return $levelID;
	}

	/**
	 * Method to change the title & alias.
	 *
	 * @param   integer  $id    Article id
	 * @param   integer  $catId The id of the category.
	 * @param   array   &$data  The row data.
	 *
	 * @return    null
	 *
	 * @since 4.0
	 */
	protected function generateNewTitle($id, $catId, &$data)
	{
		$table = Table::getInstance('Content');
		$alias = empty($data['alias']) ? $data['title'] : $data['alias'];
		$alias = ApplicationHelper::stringURLSafe(Normalise::toDashSeparated($alias));

		$data['alias'] = $alias;
		$title         = $data['title'];
		$titles        = array();
		$aliases       = array();

		// Test even if an existing article, we then remove that article title from the $titles array.
		// Means that changing an existing Fabrik title to an existing article title
		// should increment the Joomla article title.
		while ($table->load(array('alias' => $alias, 'catid' => $catId)))
		{
			$title                      = StringHelper::increment($title);
			$titles[$table->get('id')]  = $title;
			$alias                      = StringHelper::increment($alias, 'dash');
			$aliases[$table->get('id')] = $alias;
		}

		unset($titles[$id]);
		unset($aliases[$id]);
		$title = empty($titles) ? $data['title'] : array_pop($titles);
		$alias = empty($aliases) ? $data['alias'] : array_pop($aliases);

		// Update the Fabrik record's title if the article alias changes..
		if ($title <> $data['title'])
		{
			$formModel  = $this->getModel();
			$listModel  = $formModel->getListModel();
			$pkName     = $listModel->getPrimaryKey(true);
			$pk         = ArrayHelper::getValue($this->data, $pkName);
			$titleField = $formModel->getElement($this->getParams()->get('title'), true);
			$titleField = $titleField->getFullName(false, false);
			$listModel->updateRows(array($pk), $titleField, $title);
		}

		$data['title'] = $title;
		$data['alias'] = $alias;
	}

	/**
	 * Update the form model with the new meta store data
	 *
	 * @param   object $store Meta store (categoryid => articleid)
	 *
	 * @return  null
	 *
	 * @since 4.0
	 */
	protected function setMetaStore($store)
	{
		$formModel = $this->getModel();
		$params    = $this->getParams();

		if ($elementModel = $formModel->getElement($params->get('meta_store'), true))
		{
			$key       = $elementModel->getElement()->name;
			$val       = json_encode($store);
			$listModel = $formModel->getListModel();

			// Ensure we store to the main db table
			$listModel->clearTable();

			$rowId = $this->app->input->getString('rowid');
			$listModel->storeCell($rowId, $key, $val);
		}
		else
		{
			throw new \RuntimeException('setMetaStore: No meta store element found for element id ' . $params->get('meta_store'));
		}
	}

	/**
	 * Get the meta store - this is a categoryid => articleid mapping object
	 *
	 * @return  object
	 *
	 * @since 4.0
	 */
	protected function metaStore()
	{
		$formModel = $this->getModel();
		$params    = $this->getParams();
		$metaStore = new \stdClass;

		if ($elementModel = $formModel->getElement($params->get('meta_store'), true))
		{
			$fullName  = $elementModel->getFullName(true, false);
			$metaStore = $formModel->getElementData($fullName . '_raw', false, $this->data[$fullName]);
			$metaStore = json_decode($metaStore);
		}
		else
		{
			throw new \RuntimeException('metaStore: No meta store element found for element id ' . $params->get('meta_store'));
		}

		if (!is_object($metaStore))
		{
			$metaStore = new \stdClass;
		}

		return $metaStore;
	}

	/**
	 * Run from list model when deleting rows
	 *
	 * @param   array &$groups List data for deletion
	 *
	 * @return    bool
	 *
	 * @since 4.0
	 */
	public function onDeleteRowsForm(&$groups)
	{
		$formModel  = $this->getModel();
		$params     = $this->getParams();
		$deleteMode = $params->get('delete_mode', 'DELETE');
		$item       = Table::getInstance('Content');
		$userId     = $this->user->get('id');

		if ($elementModel = $formModel->getElement($params->get('meta_store'), true))
		{
			$fullName = $elementModel->getFullName(true, false) . '_raw';

			foreach ($groups as $group)
			{
				foreach ($group as $rows)
				{
					foreach ($rows as $row)
					{
						$store = json_decode($row->$fullName);

						if (is_object($store))
						{
							$store = ArrayHelper::fromObject($store);

							foreach ($store as $catId => $articleId)
							{
								switch ($deleteMode)
								{
									case 'DELETE':
										$item->delete($articleId);
										break;
									case 'UNPUBLISH':
										$item->publish(array($articleId), 0, $userId);
										break;
									case 'TRASH':
										$item->publish(array($articleId), -2, $userId);
										break;
								}
							}
						}
					}
				}
			}
		}
		else
		{
			throw new \RuntimeException('Article plugin: onDeleteRows, did not find meta store element: ' . $params->get('meta_store'));
		}
	}

	/**
	 * Create the article content
	 *
	 * @return string
	 *
	 * @since 4.0
	 */
	protected function buildContent()
	{
		$images          = $this->images();
		$formModel       = $this->getModel();
		$input           = $this->app->input;
		$params          = $this->getParams();
		$template        = Path::clean(Path_SITE . '/plugins/fabrik_form/article/tmpl/' . $params->get('template', ''));
		$contentTemplate = $params->get('template_content');
		$content         = $contentTemplate != '' ? $this->_getContentTemplate($contentTemplate) : '';
		$messageTemplate = '';

		if (File::exists($template))
		{
			$messageTemplate = File::getExt($template) == 'php' ? $this->_getPHPTemplateEmail($template) : $this
				->_getTemplateEmail($template);

			if ($content !== '')
			{
				$messageTemplate = str_replace('{content}', $content, $messageTemplate);
			}
		}

		$message = '';

		if (!empty($messageTemplate))
		{
			$message = $messageTemplate;
		}
		elseif (!empty($content))
		{
			// Joomla article template:
			$message = $content;
		}

		$message = stripslashes($message);

		$editURL  = COM_FABRIK_LIVESITE . 'index.php?option=com_' . $this->package . '&amp;view=form&amp;formid=' . $formModel->get('id') . '&amp;rowid='
			. $input->get('rowid', '', 'string');
		$viewURL  = COM_FABRIK_LIVESITE . 'index.php?option=com_' . $this->package . '&amp;view=details&amp;formid=' . $formModel->get('id') . '&amp;rowid='
			. $input->get('rowid', '', 'string');
		$editLink = '<a href="' . $editURL . '">' . FText::_('EDIT') . '</a>';
		$viewLink = '<a href="' . $viewURL . '">' . FText::_('VIEW') . '</a>';
		$message  = str_replace('{fabrik_editlink}', $editLink, $message);
		$message  = str_replace('{fabrik_viewlink}', $viewLink, $message);
		$message  = str_replace('{fabrik_editurl}', $editURL, $message);
		$message  = str_replace('{fabrik_viewurl}', $viewURL, $message);

		foreach ($images as $key => $val)
		{
			$this->data[$key] = $val;
		}

		$w      = new Worker;
		$output = $w->parseMessageForPlaceholder($message, $this->data, true);

		return $output;
	}

	/**
	 * Use a php template for advanced email templates, particularly for forms with repeat group data
	 *
	 * @param   string $tmpl Path to template
	 *
	 * @return string email message
	 *
	 * @since 4.0
	 */
	protected function _getPHPTemplateEmail($tmpl)
	{
		$emailData = $this->data;

		// Start capturing output into a buffer
		ob_start();
		$result  = require $tmpl;
		$message = ob_get_contents();
		ob_end_clean();

		if ($result === false)
		{
			return false;
		}

		return $message;
	}

	/**
	 * Template email handling routine, called if email template specified
	 *
	 * @param   string $template path to template
	 *
	 * @return  string    email message
	 *
	 * @since 4.0
	 */
	protected function _getTemplateEmail($template)
	{
		return file_get_contents($template);
	}

	/**
	 * Get content item template
	 *
	 * @param   int $contentTemplate Joomla article ID to load
	 *
	 * @return  string  content item html (translated with Joomfish if installed)
	 *
	 * @since 4.0
	 */
	protected function _getContentTemplate($contentTemplate)
	{
		if ($this->app->isClient('administrator'))
		{
			$db    = $this->db;
			$query = $db->getQuery(true);
			$query->select('introtext, ' . $db->qn('fulltext'))->from('#__content')->where('id = ' . (int) $contentTemplate);
			$db->setQuery($query);
			$res = $db->loadObject();
		}
		else
		{
			BaseDatabaseModel::addIncludePath(COM_FABRIK_BASE . 'components/com_content/models');
			/** @var ArticleModel $articleModel */
			$articleModel = BaseDatabaseModel::getInstance('Article', 'ContentModel');
			$res          = $articleModel->getItem($contentTemplate);
		}

		if ($res->fulltext !== '')
		{
			$res->fulltext = '<hr id="system-readmore" />' . $res->fulltext;
		}

		return $res->introtext . ' ' . $res->fulltext;
	}

	/**
	 * Before the record is stored, this plugin will see if it should process
	 * and if so store the form data in the session.
	 *
	 * @return  bool  Should the form model continue to save
	 *
	 * @since 4.0
	 */
	public function onBeforeStore()
	{
		$formModel  = $this->getModel();
		$params     = $this->getParams();
		$this->data = $this->getProcessData();

		if ($catElement = $formModel->getElement($params->get('categories_element'), true))
		{
			$catName    = $catElement->getFullName();
			$cat        = $catName . '_raw';
			$categories = (array) FArrayHelper::getValue($this->data, $cat);

			if (empty($categories) || is_array($categories) && $categories[0] === '')
			{
				$this->raiseError($formModel->errors, $catName, FText::_('PLG_FABRIK_FORM_ARTICLE_ERR_NO_CATEGORY'));

				return false;
			}
		}

		return true;
	}

	/**
	 * Raise an error - depends on whether you are in admin or not as to what to do
	 *
	 * @param   array  &$err   Form models error array
	 * @param   string  $field Name
	 * @param   string  $msg   Message
	 *
	 * @return  void
	 *
	 * @since 4.0
	 */
	protected function raiseError(&$err, $field, $msg)
	{
		if ($this->app->isClient('administrator'))
		{
			$this->app->enqueueMessage($msg, 'notice');
		}
		else
		{
			$err[$field][0][] = $msg;
		}
	}
}

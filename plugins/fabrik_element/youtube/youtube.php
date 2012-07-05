<?php
/**
 * Plugin element to render fields
 * @package fabrikar
 * @author peamak
 * @copyright (C) fabrikar.com
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

// Check to ensure this file is included in Joomla!
defined('_JEXEC') or die();

jimport('joomla.application.component.model');

require_once JPATH_SITE . '/components/com_fabrik/models/element.php';

class PlgFabrik_ElementYoutube extends PlgFabrik_Element
{

	protected $pluginName = 'youtube';

	/**
	 * Shows the data formatted for the list view
	 * 
	 * @param   string  $data      elements data
	 * @param   object  &$thisRow  all the data in the lists current row
	 * 
	 * @return  string	formatted value
	 */

	public function renderListData($data, &$thisRow)
	{
		return $this->constructVideoPlayer($data, 'list');
	}

	/**
	 * Do we need to include the lighbox js code
	 *
	 * @return  bool
	 */

	public function requiresLightBox()
	{
		return true;
	}

	/**
	 * Determines if the element can contain data used in sending receipts,
	 * e.g. fabrikfield returns true
	 * 
	 * @return  bool
	 */

	public function isReceiptElement()
	{
		return true;
	}

	/**
	 * Draws the html form element
	 * 
	 * @param   array  $data           to preopulate element with
	 * @param   int    $repeatCounter  repeat group counter
	 * 
	 * @return  string	elements html
	 */

	public function render($data, $repeatCounter = 0)
	{
		$params = $this->getParams();
		$element = $this->getElement();
		$data = $this->getFormModel()->data;
		$value = $this->getValue($data, $repeatCounter);
		if (JRequest::getVar('view') != 'details')
		{
			$name = $this->getHTMLName($repeatCounter);
			$id = $this->getHTMLId($repeatCounter);
			$size = $params->get('width');
			$maxlength = 255;
			$bits = array();
			$type = "text";
			if ($this->elementError != '')
			{
				$type .= " elementErrorHighlight";
			}
			if (!$this->editable)
			{
				return ($element->hidden == '1') ? '<!-- ' . $value . ' -->' : $value;
			}
			$bits['class'] = "fabrikinput inputbox $type";
			$bits['type'] = $type;
			$bits['name'] = $name;
			$bits['id'] = $id;
			//stop "'s from breaking the content out of the field.
			$bits['value'] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
			$bits['size'] = $size;
			$bits['maxlength'] = $maxlength;
			$str = "<input ";
			foreach ($bits as $key => $val)
			{
				$str .= $key . ' = "' . $val . '" ';
			}
			$str .= " />\n";
			return $str;
		}
		else
		{
			return $this->constructVideoPlayer($value);
		}
	}

	private function constructVideoPlayer($value, $mode = 'form')
	{
		$params = $this->getParams();
		// Player size

		if (($params->get('display_in_table') == 0) && $model = 'list')
		{
			$width = '170';
			$height = '142';
		}
		else
		{
			if ($params->get('or_width_player') != NULL)
			{
				$width = $params->get('or_width_player');
				$height = $params->get('or_height_player');
			}
			else
			{
				if ($params->get('player_size') == 'small')
				{
					$width = '340';
					$height = '285';
				}
				elseif ($params->get('player_size') == 'medium')
				{
					$width = '445';
					$height = '364';
				}
				elseif ($params->get('player_size') == 'normal')
				{
					$width = '500';
					$height = '405';
				}
				else
				{
					$width = '660';
					$height = '525';
				}
			}
		}
		// Include related videos
		$rel = $params->get('include_related') == 0 ? '&rel=0' : '';
		$border = $params->get('show_border') == 1 ? '&border=1' : '';

		// Enable delayed cookies
		$url = $params->get('enable_delayed_cookies') == 1 ? 'http://www.youtube-nocookie.com/v/' : 'http://www.youtube.com/v/';

		// Colors
		$color1 = JString::substr($params->get('color1'), -6);
		$color2 = JString::substr($params->get('color2'), -6);
		$vid = array_pop(explode("/", $value));
		//$$$tom: if one copies an URL from youtube, the URL has the "watch?v=" which barfs the player
		if (strstr($vid, 'watch'))
		{
			$vid = explode("=", $vid);
			unset($vid[0]); // That's the watch?v=
			$vid = implode('', $vid);
		}
		if ($vid == '')
		{
			//$$$ rob perhaps they just added in the code???
			$vid = $value;
		}
		if ($value != NULL)
		{
			if ($params->get('display_in_table') == 1 && $mode == 'list')
			{
				// Display link
				if ($params->get('display_link') == 0)
				{
					$object_vid = $value;
				}
				else
				{
					if ($params->get('display_link') == 1)
					{
						$dlink = $value;
					}
					else
					{
						if ($params->get('text_link') != NULL)
						{
							$dlink = $params->get('text_link');
						}
						else
						{
							$dlink = 'Watch Video';
						}
					}
					if ($params->get('target_link') == 1)
					{
						$object_vid = '<a href="' . $url . $vid . '" target="blank">' . $dlink . '</a>';
					}
					elseif ($params->get('target_link') == 2)
					{
						$element = $this->getElement();
						$object_vid = "<a href='" . $url . $vid . "' rel='lightbox[social " . $width . " " . $height . "]' title='" . $element->label
							. "'>" . $dlink . "</a>";
					}
					else
					{
						$object_vid = '<a href="' . $url . $vid . '">' . $dlink . '</a>';
					}
				}
			}
			else
			{
				$html = array();
				$html[] = '<object width="' . $width . '" height="' . $height . '">';
				$html[] = '<param name="movie" value="' . $url . $vid . '&hl=en&fs=1' . $rel . '&color1=0x' . $color1 . '&color2=0x' . $color2
					. $border . '"></param>';
				$html[] = '<param name="allowFullScreen" value="true"></param><param name="allowscriptaccess" value="always"></param>';
				$html[] = '<embed src="' . $url . $vid . '&hl=en&fs=1' . $rel . '&color1=0x' . $color1 . '&color2=0x' . $color2 . $border
					. '" type="application/x-shockwave-flash" allowscriptaccess="always" allowfullscreen="true" width="' . $width . '" height="'
					. $height . '"></embed>';
				$html[] = '</object>';
				$object_vid = implode("\n", $html);
			}
		}
		else
		{
			$object_vid = '';
		}
		return $object_vid;
	}

	/**
	 * Returns javascript which creates an instance of the class defined in formJavascriptClass()
	 * 
	 * @param   int  $repeatCounter  repeat group counter
	 * 
	 * @return  string
	 */

	public function elementJavascript($repeatCounter)
	{
		$id = $this->getHTMLId($repeatCounter);
		$opts = $this->getElementJSOptions($repeatCounter);
		$opts = json_encode($opts);
		return "new FbYouTube('$id', $opts)";
	}

	/**
	 * get the class to manage the form element
	 * if a plugin class requires to load another elements class (eg user for dbjoin then it should
	 * call FabrikModelElement::formJavascriptClass('plugins/fabrik_element/databasejoin/databasejoin.js', true);
	 * to ensure that the file is loaded only once
	 * 
	 * @param   array   &$srcs   scripts previously loaded (load order is important as we are loading via head.js
	 * and in ie these load async. So if you this class extends another you need to insert its location in $srcs above the
	 * current file
	 * @param   string  $script  script to load once class has loaded
	 *
	 * @return void
	 */

	public function formJavascriptClass(&$srcs, $script = '')
	{
		PlgFabrik_Element::formJavascriptClass($srcs, 'plugins/fabrik_element/youtube/youtube.js');
		parent::formJavascriptClass($srcs);
	}

	/**
	 * Get database field description
	 * 
	 * @return  string  db field type
	 */

	public function getFieldDescription()
	{
		$p = $this->getParams();
		if ($this->encryptMe())
		{
			return 'BLOB';
		}
		$group = $this->getGroup();
		if ($group->isJoin() == 0 && $group->canRepeat())
		{
			return "TEXT";
		}
		else
		{
			$objtype = "VARCHAR(" . $p->get('maxlength', 255) . ")";
		}
		return $objtype;
	}

}
?>
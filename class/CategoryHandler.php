<?php

namespace XoopsModules\Publisher;

/*
 You may not change or alter any portion of this comment or credits
 of supporting developers from this source code or any supporting source code
 which is considered copyrighted (c) material of the original comment or credit authors.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 */

/**
 * @copyright       The XUUPS Project http://sourceforge.net/projects/xuups/
 * @license         http://www.fsf.org/copyleft/gpl.html GNU public license
 * @package         Publisher
 * @since           1.0
 * @author          trabis <lusopoemas@gmail.com>
 * @author          The SmartFactory <www.smartfactory.ca>
 */

use XoopsModules\Publisher;

// defined('XOOPS_ROOT_PATH') || die('Restricted access');

require_once dirname(__DIR__) . '/include/common.php';

/**
 * Categories handler class.
 * This class is responsible for providing data access mechanisms to the data source
 * of Category class objects.
 *
 * @author  marcan <marcan@notrevie.ca>
 * @package Publisher
 */
class CategoryHandler extends \XoopsPersistableObjectHandler
{
    /**
     * @var Helper
     */
    public $helper;

    /**
     * @param null|\XoopsDatabase $db
     */
    public function __construct(\XoopsDatabase $db = null)
    {
        /** @var Publisher\Helper $this->helper */
        $this->helper = Publisher\Helper::getInstance();
        parent::__construct($db, 'publisher_categories', Category::class, 'categoryid', 'name');
    }

    /**
     * retrieve an item
     *
     * @param int|null $id  itemid of the user
     *
     * @param  null    $fields
     * @return mixed reference to the <a href='psi_element://Publisher\Category'>Publisher\Category</a> object, FALSE if failed
     *                      object, FALSE if failed
     */
    public function get($id = null, $fields = null)
    {
        static $cats;
        if (isset($cats[$id])) {
            return $cats[$id];
        }
        $obj       = parent::get($id);
        $cats[$id] = $obj;

        return $obj;
    }

    /**
     * insert a new category in the database
     *
     * @param \XoopsObject $category reference to the {@link Publisher\Category}
     * @param  bool        $force
     * @return bool        FALSE if failed, TRUE if already present and unchanged or successful
     */
    public function insert(\XoopsObject $category, $force = false) //insert(&$category, $force = false)
    {
        // Auto create meta tags if empty
        /** @var \XoopsModules\Publisher\Category $category */
        if (!$category->meta_keywords || !$category->meta_description) {
            $publisherMetagen = new Publisher\Metagen($category->name, $category->getVar('meta_keywords'), $category->getVar('description'));
            if (!$category->meta_keywords) {
                $category->setVar('meta_keywords', $publisherMetagen->keywords);
            }
            if (!$category->meta_description) {
                $category->setVar('meta_description', $publisherMetagen->description);
            }
        }
        // Auto create short_url if empty
        if (!$category->short_url) {
            $category->setVar('short_url', Publisher\Metagen::generateSeoTitle($category->name('n'), false));
        }
        $ret = parent::insert($category, $force);

        return $ret;
    }

    /**
     * delete a category from the database
     *
     * @param \XoopsObject $category reference to the category to delete
     * @param bool         $force
     *
     * @return bool FALSE if failed.
     */
    public function delete(\XoopsObject $category, $force = false) //delete(&$category, $force = false)
    {
        /** @var \XoopsModules\Publisher\Category $category */
        // Deleting this category ITEMs
        $criteria = new \Criteria('categoryid', $category->categoryid);
        $this->helper->getHandler('Item')->deleteAll($criteria);
        unset($criteria);
        // Deleting the sub categories
        $subcats =& $this->getCategories(0, 0, $category->categoryid);
        foreach ($subcats as $subcat) {
            $this->delete($subcat);
        }
        if (!parent::delete($category, $force)) {
            $category->setErrors('An error while deleting.');

            return false;
        }
        $moduleId = $this->helper->getModule()->getVar('mid');
        xoops_groupperm_deletebymoditem($moduleId, 'category_read', $category->categoryid);
        xoops_groupperm_deletebymoditem($moduleId, 'item_submit', $category->categoryid);
        xoops_groupperm_deletebymoditem($moduleId, 'category_moderation', $category->categoryid);

        return true;
    }

    /**
     * retrieve categories from the database
     *
     * @param \CriteriaElement $criteria {@link CriteriaElement} conditions to be met
     * @param bool             $idAsKey  use the categoryid as key for the array?
     *
     * @param  bool            $as_object
     * @return array array of <a href='psi_element://XoopsItem'>XoopsItem</a> objects
     */

    public function &getObjects(\CriteriaElement $criteria = null, $idAsKey = false, $as_object = true) //&getObjects($criteria = null, $idAsKey = false)
    {
        $ret        = [];
        $theObjects = parent::getObjects($criteria, true);
        foreach ($theObjects as $theObject) {
            if (!$idAsKey) {
                $ret[] = $theObject;
            } else {
                $ret[$theObject->categoryid()] = $theObject;
            }
            unset($theObject);
        }

        return $ret;
    }

    /**
     * @param int    $limit
     * @param int    $start
     * @param int    $parentid
     * @param string $sort
     * @param string $order
     * @param bool   $idAsKey
     *
     * @return array
     */
    public function &getCategories($limit = 0, $start = 0, $parentid = 0, $sort = 'weight', $order = 'ASC', $idAsKey = true)
    {
        //        global $publisherIsAdmin;
        $criteria = new \CriteriaCompo();
        $criteria->setSort($sort);
        $criteria->setOrder($order);
        if (-1 != $parentid) {
            $criteria->add(new \Criteria('parentid', $parentid));
        }
        if (!$GLOBALS['publisherIsAdmin']) {
            /** @var Publisher\PermissionHandler $permissionHandler */
            $permissionHandler = $this->helper->getHandler('Permission');
            $categoriesGranted = $permissionHandler->getGrantedItems('category_read');
            if (count($categoriesGranted) > 0) {
                $criteria->add(new \Criteria('categoryid', '(' . implode(',', $categoriesGranted) . ')', 'IN'));
            } else {
                return [];
            }
            if (is_object($GLOBALS['xoopsUser'])) {
                $criteria->add(new \Criteria('moderator', $GLOBALS['xoopsUser']->getVar('uid')), 'OR');
            }
        }
        $criteria->setStart($start);
        $criteria->setLimit($limit);
        $ret = $this->getObjects($criteria, $idAsKey);

        return $ret;
    }

    /**
     * @param $category
     * @param $level
     * @param $catArray
     * @param $catResult
     */
    public function getSubCatArray($category, $level, $catArray, $catResult)
    {
        global $theresult;
        $spaces = '';
        for ($j = 0; $j < $level; ++$j) {
            $spaces .= '--';
        }
        $theresult[$category['categoryid']] = $spaces . $category['name'];
        if (isset($catArray[$category['categoryid']])) {
            ++$level;
            foreach ($catArray[$category['categoryid']] as $parentid => $cat) {
                $this->getSubCatArray($cat, $level, $catArray, $catResult);
            }
        }
    }

    /**
     * @return array
     */
    public function &getCategoriesForSubmit()
    {
        global $publisherIsAdmin, $theresult;
        $ret      = [];
        $criteria = new \CriteriaCompo();
        $criteria->setSort('name');
        $criteria->setOrder('ASC');
        if (!$publisherIsAdmin) {
            $categoriesGranted = $this->helper->getHandler('Permission')->getGrantedItems('item_submit');
            if (count($categoriesGranted) > 0) {
                $criteria->add(new \Criteria('categoryid', '(' . implode(',', $categoriesGranted) . ')', 'IN'));
            } else {
                return $ret;
            }
            if (is_object($GLOBALS['xoopsUser'])) {
                $criteria->add(new \Criteria('moderator', $GLOBALS['xoopsUser']->getVar('uid')), 'OR');
            }
        }
        $categories = $this->getAll($criteria, ['categoryid', 'parentid', 'name'], false, false);
        if (0 == count($categories)) {
            return $ret;
        }
        $catArray = [];
        foreach ($categories as $cat) {
            $catArray[$cat['parentid']][$cat['categoryid']] = $cat;
        }
        // Needs to have permission on at least 1 top level category
        if (!isset($catArray[0])) {
            return $ret;
        }
        $catResult = [];
        foreach ($catArray[0] as $thecat) {
            $level = 0;
            $this->getSubCatArray($thecat, $level, $catArray, $catResult);
        }

        return $theresult; //this is a global
    }

    /**
     * @return array
     */
    public function getCategoriesForSearch()
    {
        global $publisherIsAdmin, $theresult;
        $ret      = [];
        $criteria = new \CriteriaCompo();
        $criteria->setSort('name');
        $criteria->setOrder('ASC');
        if (!$publisherIsAdmin) {
            $categoriesGranted = $this->helper->getHandler('Permission')->getGrantedItems('category_read');
            if (count($categoriesGranted) > 0) {
                $criteria->add(new \Criteria('categoryid', '(' . implode(',', $categoriesGranted) . ')', 'IN'));
            } else {
                return $ret;
            }
            if (is_object($GLOBALS['xoopsUser'])) {
                $criteria->add(new \Criteria('moderator', $GLOBALS['xoopsUser']->getVar('uid')), 'OR');
            }
        }
        $categories = $this->getAll($criteria, ['categoryid', 'parentid', 'name'], false, false);
        if (0 == count($categories)) {
            return $ret;
        }
        $catArray = [];
        foreach ($categories as $cat) {
            $catArray[$cat['parentid']][$cat['categoryid']] = $cat;
        }
        // Needs to have permission on at least 1 top level category
        if (!isset($catArray[0])) {
            return $ret;
        }
        $catResult = [];
        foreach ($catArray[0] as $thecat) {
            $level = 0;
            $this->getSubCatArray($thecat, $level, $catArray, $catResult);
        }

        return $theresult; //this is a global
    }

    /**
     * @param int $parentid
     *
     * @return int
     */
    public function getCategoriesCount($parentid = 0)
    {
        //        global $publisherIsAdmin;
        if (-1 == $parentid) {
            return $this->getCount();
        }
        $criteria = new \CriteriaCompo();
        if (isset($parentid) && (-1 != $parentid)) {
            $criteria->add(new \Criteria('parentid', $parentid));
            if (!$GLOBALS['publisherIsAdmin']) {
                $categoriesGranted = $this->helper->getHandler('Permission')->getGrantedItems('category_read');
                if (count($categoriesGranted) > 0) {
                    $criteria->add(new \Criteria('categoryid', '(' . implode(',', $categoriesGranted) . ')', 'IN'));
                } else {
                    return 0;
                }
                if (is_object($GLOBALS['xoopsUser'])) {
                    $criteria->add(new \Criteria('moderator', $GLOBALS['xoopsUser']->getVar('uid')), 'OR');
                }
            }
        }

        return $this->getCount($criteria);
    }

    /**
     * Get all subcats and put them in an array indexed by parent id
     *
     * @param array $categories
     *
     * @return array
     */
    public function getSubCats($categories)
    {
        //        global $publisherIsAdmin;
        $criteria = new \CriteriaCompo(new \Criteria('parentid', '(' . implode(',', array_keys($categories)) . ')', 'IN'));
        $ret      = [];
        if (!$GLOBALS['publisherIsAdmin']) {
            $categoriesGranted = $this->helper->getHandler('Permission')->getGrantedItems('category_read');
            if (count($categoriesGranted) > 0) {
                $criteria->add(new \Criteria('categoryid', '(' . implode(',', $categoriesGranted) . ')', 'IN'));
            } else {
                return $ret;
            }

            if (is_object($GLOBALS['xoopsUser'])) {
                $criteria->add(new \Criteria('moderator', $GLOBALS['xoopsUser']->getVar('uid')), 'OR');
            }
        }
        $criteria->setSort('weight');
        $criteria->setOrder('ASC');
        $subcats = $this->getObjects($criteria, true);
        foreach ($subcats as $subcat) {
            $ret[$subcat->getVar('parentid')][$subcat->getVar('categoryid')] = $subcat;
        }

        return $ret;
    }

    /**
     * delete categories matching a set of conditions
     *
     * @param \CriteriaElement $criteria {@link CriteriaElement}
     *
     * @param  bool            $force
     * @param  bool            $asObject
     * @return bool FALSE if deletion failed
     */
    public function deleteAll(\CriteriaElement $criteria = null, $force = true, $asObject = false) //deleteAll($criteria = null)
    {
        $categories = $this->getObjects($criteria);
        foreach ($categories as $category) {
            if (!$this->delete($category)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param int $catId
     *
     * @return mixed
     */
    public function publishedItemsCount($catId = 0)
    {
        return $this->itemsCount($catId, $status = [Constants::PUBLISHER_STATUS_PUBLISHED]);
    }

    /**
     * @param int    $catId
     * @param string $status
     *
     * @return mixed
     */
    public function itemsCount($catId = 0, $status = '')
    {
        /** @var Publisher\ItemHandler $itemHandler */
        $itemHandler = $this->helper->getHandler('Item');
        return $itemHandler->getCountsByCat($catId, $status);
    }
}

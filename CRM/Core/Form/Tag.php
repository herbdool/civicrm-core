<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */

/**
 * This class generates form element for free tag widget.
 */
class CRM_Core_Form_Tag {

  /**
   * Build tag widget if correct parent is passed
   *
   * @param CRM_Core_Form $form
   *   Form object.
   * @param array $parentNames
   *   Parent name ( tag name).
   * @param string $entityTable
   *   Entitytable 'eg: civicrm_contact'.
   * @param int $entityId
   *   Entityid 'eg: contact id'.
   * @param bool $skipTagCreate
   *   True if tag need be created using ajax.
   * @param bool $skipEntityAction
   *   True if need to add entry in entry table via ajax.
   * @param string $tagsetElementName
   *   If you need to create tagsetlist with specific name.
   */
  public static function buildQuickForm(
    &$form, $parentNames, $entityTable, $entityId = NULL, $skipTagCreate = FALSE,
    $skipEntityAction = FALSE, $tagsetElementName = NULL) {
    $tagset = [];
    $form->_entityTagValues ??= [];
    // Initialize isTagset tpl var if it hasn't been initialized already
    $isTagset = $form->getTemplateVars('isTagset');
    $form->assign('isTagset', (bool) $isTagset);
    $mode = NULL;

    foreach ($parentNames as $parentId => $parentNameItem) {
      // check if parent exists
      if ($parentId) {
        $tagsetItem = $tagsetElementName . 'parentId_' . $parentId;
        $tagset[$tagsetItem]['parentID'] = $parentId;

        [, $mode] = explode('_', $entityTable);
        if (!$tagsetElementName) {
          $tagsetElementName = $mode . "_taglist";
        }
        $tagset[$tagsetItem]['tagsetElementName'] = $tagsetElementName;

        $form->addEntityRef("{$tagsetElementName}[{$parentId}]", $parentNameItem, [
          'entity' => 'Tag',
          'multiple' => TRUE,
          'create' => !$skipTagCreate,
          'api' => ['params' => ['parent_id' => $parentId]],
          'data-entity_table' => $entityTable,
          'data-entity_id' => $entityId,
          'class' => "crm-$mode-tagset",
          'select' => ['minimumInputLength' => 0],
        ]);

        if ($entityId) {
          $tagset[$tagsetItem]['entityId'] = $entityId;
          $entityTags = CRM_Core_BAO_EntityTag::getChildEntityTags($parentId, $entityId, $entityTable);
          if ($entityTags) {
            $form->setDefaults(["{$tagsetElementName}[{$parentId}]" => implode(',', array_keys($entityTags))]);
          }
        }
        else {
          $skipEntityAction = TRUE;
        }
        $tagset[$tagsetItem]['skipEntityAction'] = $skipEntityAction;
      }
    }

    $form->addExpectedSmartyVariable('tagsetInfo');
    if (!empty($tagset)) {
      // assign current tagsets which is used in postProcess
      $form->_tagsetInfo = $tagset;
      $form->assign("tagsetType", $mode);
      // Merge this tagset info with possibly existing info in the template
      $tagsetInfo = (array) $form->getTemplateVars("tagsetInfo");
      if (empty($tagsetInfo[$mode])) {
        $tagsetInfo[$mode] = [];
      }
      $tagsetInfo[$mode] = array_merge($tagsetInfo[$mode], $tagset);
      $form->assign('tagsetInfo', $tagsetInfo);
      $form->assign('isTagset', TRUE);
    }
  }

  /**
   * Save entity tags when it is not save used AJAX.
   *
   * @param array $params
   * @param int $entityId
   *   Entity id, eg: contact id, activity id, case id, file id.
   * @param string $entityTable
   *   Entity table.
   * @param CRM_Core_Form $form
   *   Form object.
   */
  public static function postProcess(&$params, $entityId, $entityTable = 'civicrm_contact', &$form = NULL) {
    if ($form && !empty($form->_entityTagValues)) {
      $existingTags = $form->_entityTagValues;
    }
    else {
      $existingTags = CRM_Core_BAO_EntityTag::getTag($entityId, $entityTable);
    }

    if ($form) {
      // if the key is missing from the form response then all the tags were deleted / cleared
      // in that case we create empty tagset params so that below logic works and tagset are
      // deleted correctly
      foreach ($form->_tagsetInfo as $tagsetName => $tagsetInfo) {
        $tagsetId = explode('parentId_', $tagsetName);
        $tagsetId = $tagsetId[1];
        if (empty($params[$tagsetId])) {
          $params[$tagsetId] = '';
        }
      }
    }

    // when form is submitted with tagset values below logic will work and in the case when all tags in a tagset
    // are deleted we will have to set $params[tagset id] = '' which is done by above logic
    foreach ($params as $parentId => $value) {
      $newTagIds = [];
      $tagIds = [];

      if ($value) {
        $tagIds = explode(',', $value);
        foreach ($tagIds as $tagId) {
          if ($form && $form->_action != CRM_Core_Action::UPDATE || !array_key_exists($tagId, $existingTags)) {
            $newTagIds[] = $tagId;
          }
        }
      }

      // Any existing entity tags from this tagset missing from the $params should be deleted
      $deleteSQL = "DELETE FROM civicrm_entity_tag
                    USING civicrm_entity_tag, civicrm_tag
                    WHERE civicrm_tag.id=civicrm_entity_tag.tag_id
                      AND civicrm_entity_tag.entity_table='{$entityTable}'
                      AND entity_id={$entityId} AND parent_id={$parentId}";
      if (!empty($tagIds)) {
        $deleteSQL .= " AND tag_id NOT IN (" . implode(', ', $tagIds) . ");";
      }

      CRM_Core_DAO::executeQuery($deleteSQL);

      if (!empty($newTagIds)) {
        // New tag ids can be inserted directly into the db table.
        $insertValues = [];
        foreach ($newTagIds as $tagId) {
          $insertValues[] = "( {$tagId}, {$entityId}, '{$entityTable}' ) ";
        }
        $insertSQL = 'INSERT INTO civicrm_entity_tag ( tag_id, entity_id, entity_table )
          VALUES ' . implode(', ', $insertValues) . ';';
        CRM_Core_DAO::executeQuery($insertSQL);
      }
    }
  }

}

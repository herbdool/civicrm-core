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

namespace Civi\Api4\Service\Spec\Provider;

use Civi\Api4\Query\Api4SelectQuery;
use Civi\Api4\Service\Spec\FieldSpec;
use Civi\Api4\Service\Spec\RequestSpec;

/**
 * @service
 * @internal
 */
class ContributionGetSpecProvider extends \Civi\Core\Service\AutoService implements Generic\SpecProviderInterface {

  /**
   * @param \Civi\Api4\Service\Spec\RequestSpec $spec
   *
   * @throws \CRM_Core_Exception
   */
  public function modifySpec(RequestSpec $spec): void {
    // Add calculated fields
    $field = new FieldSpec('paid_amount', 'Contribution', 'Float');
    $field->setLabel(ts('Amount Paid'))
      ->setTitle(ts('Amount Paid'))
      ->setDescription(ts('Amount paid'))
      ->setType('Extra')
      ->setDataType('Money')
      ->setReadonly(TRUE)
      ->setColumnName('id')
      ->setSqlRenderer([__CLASS__, 'calculateAmountPaid']);
    $spec->addFieldSpec($field);
    $field = new FieldSpec('balance_amount', 'Contribution', 'Float');
    $field->setLabel(ts('Balance'))
      ->setTitle(ts('Balance'))
      ->setDescription(ts('Balance'))
      ->setType('Extra')
      ->setDataType('Money')
      ->setColumnName('total_amount')
      ->setReadonly(TRUE)
      ->setSqlRenderer([__CLASS__, 'calculateBalance']);
    $spec->addFieldSpec($field);
    $field = new FieldSpec('tax_exclusive_amount', 'Contribution', 'Float');
    $field->setLabel(ts('Tax Exclusive Amount'))
      ->setTitle(ts('Tax Exclusive Amount'))
      ->setDescription(ts('Tax Exclusive Amount'))
      ->setType('Extra')
      ->setDataType('Money')
      ->setReadonly(TRUE)
      ->setColumnName('total_amount')
      ->setSqlRenderer([__CLASS__, 'calculateTaxExclusiveAmount']);
    $spec->addFieldSpec($field);
  }

  /**
   * @param string $entity
   * @param string $action
   *
   * @return bool
   */
  public function applies($entity, $action): bool {
    return $entity === 'Contribution' && $action === 'get';
  }

  /**
   * Generate SQL for amount_paid field
   *
   * @return string
   */
  public static function calculateTaxExclusiveAmount(array $totalAmountField, Api4SelectQuery $query): string {
    $taxAmountField = $query->getFieldSibling($totalAmountField, 'tax_amount');
    return "COALESCE({$totalAmountField['sql_name']}, 0) - COALESCE({$taxAmountField['sql_name']}, 0)";
  }

  /**
   * Generate SQL for amount_paid field
   *
   * @return string
   */
  public static function calculateAmountPaid(array $idField, Api4SelectQuery $query): string {
    $statusIDs = [
      \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed'),
      \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Refunded'),
    ];

    return "COALESCE((SELECT SUM(ft.total_amount) FROM civicrm_financial_trxn ft
      INNER JOIN civicrm_entity_financial_trxn eft ON (eft.financial_trxn_id = ft.id AND eft.entity_table = 'civicrm_contribution')
      WHERE eft.entity_id = {$idField['sql_name']} AND ft.is_payment = 1 AND ft.status_id IN (" . implode(',', $statusIDs) . ')), 0)';
  }

  /**
   * Generate SQL for age field
   *
   * @return string
   */
  public static function calculateBalance(array $totalAmountField, Api4SelectQuery $query): string {
    $statusIDs = [
      \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed'),
      \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Refunded'),
    ];
    $idField = $query->getFieldSibling($totalAmountField, 'id');

    return "{$totalAmountField['sql_name']} - COALESCE((SELECT SUM(ft.total_amount) FROM civicrm_financial_trxn ft
      INNER JOIN civicrm_entity_financial_trxn eft ON (eft.financial_trxn_id = ft.id AND eft.entity_table = 'civicrm_contribution')
      WHERE eft.entity_id = {$idField['sql_name']} AND ft.is_payment = 1 AND ft.status_id IN (" . implode(',', $statusIDs) . ')), 0)';
  }

}

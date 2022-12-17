<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace PrestaShop\Module\FacetedSearch\Product;

use Category;
use Configuration;
use Context;
use FrontController;
use Group;
use PrestaShop\Module\FacetedSearch\Adapter\AbstractAdapter;
use PrestaShop\Module\FacetedSearch\Adapter\MySQL as MySQLAdapter;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchQuery;

class Search
{
    const STOCK_MANAGEMENT_FILTER = 'with_stock_management';

    /**
     * @var bool
     */
    protected $psStockManagement;

    /**
     * @var bool
     */
    protected $psOrderOutOfStock;

    /**
     * @var AbstractAdapter
     */
    protected $searchAdapter;

    /**
     * @var Context
     */
    protected $context;

    /**
     * @var ProductSearchQuery
     */
    protected $query;

    /**
     * Search constructor.
     *
     * @param Context $context
     * @param string $adapterType
     */
    public function __construct(Context $context, $adapterType = MySQLAdapter::TYPE)
    {
        $this->context = $context;

        switch ($adapterType) {
            case MySQLAdapter::TYPE:
            default:
                $this->searchAdapter = new MySQLAdapter();
        }

        if ($this->psStockManagement === null) {
            $this->psStockManagement = (bool) Configuration::get('PS_STOCK_MANAGEMENT');
        }

        if ($this->psOrderOutOfStock === null) {
            $this->psOrderOutOfStock = (bool) Configuration::get('PS_ORDER_OUT_OF_STOCK');
        }
    }

    /**
     * @return AbstractAdapter
     */
    public function getSearchAdapter()
    {
        return $this->searchAdapter;
    }

    /**
     * @return ProductSearchQuery
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * @param ProductSearchQuery $query
     *
     * @return $this
     */
    public function setQuery(ProductSearchQuery $query)
    {
        $this->query = $query;

        return $this;
    }

    /**
     * Init the initial population of the search filter
     *
     * @param array $selectedFilters
     */
    public function initSearch($selectedFilters)
    {
        // Get category ID from the query or home category as a fallback
        $idCategory = (int) $this->query->getIdCategory();
        if (empty($idCategory)) {
            $idCategory = (int) Configuration::get('PS_HOME_CATEGORY');
        }

        $psLayeredFullTree = Configuration::get('PS_LAYERED_FULL_TREE');
        if (!$psLayeredFullTree) {
            $this->addFilter('id_category', [$idCategory]);
        }

        $psLayeredFilterByDefaultCategory = Configuration::get('PS_LAYERED_FILTER_BY_DEFAULT_CATEGORY');
        if ($psLayeredFilterByDefaultCategory) {
            $this->addFilter('id_category_default', [$idCategory]);
        }

        // Visibility of a product must be in catalog or both (search & catalog)
        $this->addFilter('visibility', ['both', 'catalog']);

        // User must belong to one of the groups that can access the product
        if (Group::isFeatureActive()) {
            $groups = FrontController::getCurrentCustomerGroups();

            $this->addFilter('id_group', empty($groups) ? [Group::getCurrent()->id] : $groups);
        }

        $this->addSearchFilters(
            $selectedFilters,
            $psLayeredFullTree ? new Category($idCategory) : null,
            (int) $this->context->shop->id
        );
    }

    /**
     * @param array $selectedFilters
     * @param Category $parentCategory
     * @param int $idShop
     */
    private function addSearchFilters($selectedFilters, $parentCategory, $idShop)
    {
        $categoryFilterApplied = false;
        foreach ($selectedFilters as $key => $filterValues) {
            if (!count($filterValues)) {
                continue;
            }

            switch ($key) {
                case 'id_feature':
                    $operationsFilter = [];
                    foreach ($filterValues as $featureId => $filterValue) {
                        $this->getSearchAdapter()->addOperationsFilter(
                            'with_features_' . $featureId,
                            [[['id_feature_value', $filterValue]]]
                        );
                    }
                    break;

                case 'id_attribute_group':
                    $operationsFilter = [];
                    foreach ($filterValues as $attributeId => $filterValue) {
                        $this->getSearchAdapter()->addOperationsFilter(
                            'with_attributes_' . $attributeId,
                            [[['id_attribute', $filterValue]]]
                        );
                    }
                    break;

                case 'category':
                    $this->addFilter('id_category', $filterValues);
                    $this->getSearchAdapter()->resetFilter('id_category_default');
                    $categoryFilterApplied = true;
                    break;

                case 'quantity':
                    /*
                    * $filterValues options can have following values:
                    * 0 - Not available - 0 or less quantity and disabled backorders
                    * 1 - Available - Positive quantity or enabled backorders
                    * 2 - In stock - Positive quantity
                    */

                    // If all three values are checked, we show everything
                    if (count($filterValues) == 3) {
                        break;
                    }

                    // If stock management is deactivated, we show everything
                    if (!$this->psStockManagement) {
                        break;
                    }

                    $operationsFilter = [];

                    // Simple cases with 1 option selected
                    if (count($filterValues) == 1) {
                        // Not available
                        if ($filterValues[0] == 0) {
                            $operationsFilter[] = [
                                ['quantity', [0], '<='],
                                ['out_of_stock', $this->psOrderOutOfStock ? [0] : [0, 2], '='],
                            ];
                        // Available
                        } elseif ($filterValues[0] == 1) {
                            $operationsFilter[] = [
                                ['out_of_stock', $this->psOrderOutOfStock ? [1, 2] : [1], '='],
                            ];
                            $operationsFilter[] = [
                                ['quantity', [0], '>'],
                            ];
                        // In stock
                        } elseif ($filterValues[0] == 2) {
                            $operationsFilter[] = [
                                ['quantity', [0], '>'],
                            ];
                        }
                        // Cases with 2 options selected
                    } elseif (count($filterValues) == 2) {
                        // Not available and available, we show everything
                        if (in_array(0, $filterValues) && in_array(1, $filterValues)) {
                            break;
                        // Not available or in stock
                        } elseif (in_array(0, $filterValues) && in_array(2, $filterValues)) {
                            $operationsFilter[] = [
                                ['quantity', [0], '<='],
                                ['out_of_stock', $this->psOrderOutOfStock ? [0] : [0, 2], '='],
                            ];
                            $operationsFilter[] = [
                                ['quantity', [0], '>'],
                            ];
                        // Available or in stock
                        } elseif (in_array(1, $filterValues) && in_array(2, $filterValues)) {
                            $operationsFilter[] = [
                                ['out_of_stock', $this->psOrderOutOfStock ? [1, 2] : [1], '='],
                            ];
                            $operationsFilter[] = [
                                ['quantity', [0], '>'],
                            ];
                        }
                    }

                    $this->getSearchAdapter()->addOperationsFilter(
                        self::STOCK_MANAGEMENT_FILTER,
                        $operationsFilter
                    );
                    break;

                case 'manufacturer':
                    $this->addFilter('id_manufacturer', $filterValues);
                    break;

                case 'condition':
                    if (count($selectedFilters['condition']) == 3) {
                        break;
                    }
                    $this->addFilter('condition', $filterValues);
                    break;

                case 'weight':
                    if (!empty($selectedFilters['weight'][0]) || !empty($selectedFilters['weight'][1])) {
                        $this->getSearchAdapter()->addFilter(
                            'weight',
                            [(float) $selectedFilters['weight'][0]],
                            '>='
                        );
                        $this->getSearchAdapter()->addFilter(
                            'weight',
                            [(float) $selectedFilters['weight'][1]],
                            '<='
                        );
                    }
                    break;

                case 'price':
                    if (isset($selectedFilters['price'])
                        && (
                            $selectedFilters['price'][0] !== '' || $selectedFilters['price'][1] !== ''
                        )
                    ) {
                        $this->addPriceFilter(
                            (float) $selectedFilters['price'][0],
                            (float) $selectedFilters['price'][1]
                        );
                    }
                    break;
            }
        }

        // If no category filter was applied, we will use the current category,
        // products of this category or it's children
        if (!$categoryFilterApplied && $parentCategory !== null) {
            $this->getSearchAdapter()->addFilter('nleft', [$parentCategory->nleft], '>=');
            $this->getSearchAdapter()->addFilter('nright', [$parentCategory->nright], '<=');
        }

        // Apply basic filtering for ID shop and avoid duplicate results that may occur
        $this->getSearchAdapter()->addFilter('id_shop', [$idShop]);
        $this->getSearchAdapter()->addGroupBy('id_product');

        $this->getSearchAdapter()->useFiltersAsInitialPopulation();
    }

    /**
     * Add a filter with the filterValues extracted from the selectedFilters
     *
     * @param string $filterName
     * @param array $filterValues
     */
    public function addFilter($filterName, array $filterValues)
    {
        $values = [];
        foreach ($filterValues as $filterValue) {
            if (is_array($filterValue)) {
                foreach ($filterValue as $subFilterValue) {
                    $values[] = (int) $subFilterValue;
                }
            } else {
                $values[] = $filterValue;
            }
        }

        if (!empty($values)) {
            $this->getSearchAdapter()->addFilter($filterName, $values);
        }
    }

    /**
     * Add a price filter
     *
     * @param float $minPrice
     * @param float $maxPrice
     */
    private function addPriceFilter($minPrice, $maxPrice)
    {
        $this->getSearchAdapter()->addFilter('price_min', [$maxPrice], '<=');
        $this->getSearchAdapter()->addFilter('price_max', [$minPrice], '>=');
    }
}

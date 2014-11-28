<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magentocommerce.com for more information.
 *
 * @copyright   Copyright (c) 2014 X.commerce, Inc. (http://www.magentocommerce.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
namespace Magento\Catalog\Model\Product\Option\Type;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Model\Exception;

/**
 * Catalog product option file type
 *
 * @author     Magento Core Team <core@magentocommerce.com>
 */
class File extends \Magento\Catalog\Model\Product\Option\Type\DefaultType
{
    /**
     * Url for custom option download controller
     * @var string
     */
    protected $_customOptionDownloadUrl = 'sales/download/downloadCustomOption';

    /**
     * @var string|null
     */
    protected $_formattedOptionValue = null;

    /**
     * @var \Magento\Framework\Filesystem\Directory\ReadInterface
     */
    protected $_rootDirectory;

    /**
     * Core file storage database
     *
     * @var \Magento\Core\Helper\File\Storage\Database
     */
    protected $_coreFileStorageDatabase = null;

    /**
     * @var \Magento\Framework\Escaper
     */
    protected $_escaper;

    /**
     * Url
     *
     * @var \Magento\Catalog\Model\Product\Option\UrlBuilder
     */
    protected $_urlBuilder;

    /**
     * Item option factory
     *
     * @var \Magento\Sales\Model\Quote\Item\OptionFactory
     */
    protected $_itemOptionFactory;

    /**
     * @var File\ValidatorInfo
     */
    protected $validatorInfo;

    /**
     * @var File\ValidatorFile
     */
    protected $validatorFile;

    /**
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Sales\Model\Quote\Item\OptionFactory $itemOptionFactory
     * @param \Magento\Catalog\Model\Product\Option\UrlBuilder $urlBuilder
     * @param \Magento\Framework\Escaper $escaper
     * @param \Magento\Core\Helper\File\Storage\Database $coreFileStorageDatabase
     * @param File\ValidatorInfo $validatorInfo
     * @param File\ValidatorFile $validatorFile
     * @param array $data
     * @throws \Magento\Framework\Filesystem\FilesystemException
     */
    public function __construct(
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Sales\Model\Quote\Item\OptionFactory $itemOptionFactory,
        \Magento\Core\Helper\File\Storage\Database $coreFileStorageDatabase,
        \Magento\Catalog\Model\Product\Option\Type\File\ValidatorInfo $validatorInfo,
        \Magento\Catalog\Model\Product\Option\Type\File\ValidatorFile $validatorFile,
        \Magento\Catalog\Model\Product\Option\UrlBuilder $urlBuilder,
        \Magento\Framework\Escaper $escaper,
        array $data = array()
    ) {
        $this->_itemOptionFactory = $itemOptionFactory;
        $this->_urlBuilder = $urlBuilder;
        $this->_escaper = $escaper;
        $this->_coreFileStorageDatabase = $coreFileStorageDatabase;
        $this->validatorInfo = $validatorInfo;
        $this->validatorFile = $validatorFile;
        parent::__construct($checkoutSession, $scopeConfig, $data);
    }

    /**
     * Flag to indicate that custom option has own customized output (blocks, native html etc.)
     *
     * @return boolean
     */
    public function isCustomizedView()
    {
        return true;
    }

    /**
     * Return option html
     *
     * @param array $optionInfo
     * @return string|void
     */
    public function getCustomizedView($optionInfo)
    {
        try {
            if (isset($optionInfo['option_value'])) {
                return $this->_getOptionHtml($optionInfo['option_value']);
            } elseif (isset($optionInfo['value'])) {
                return $optionInfo['value'];
            }
        } catch (\Exception $e) {
            return $optionInfo['value'];
        }
    }

    /**
     * Returns additional params for processing options
     *
     * @return \Magento\Framework\Object
     */
    protected function _getProcessingParams()
    {
        $buyRequest = $this->getRequest();
        $params = $buyRequest->getData('_processing_params');
        /*
         * Notice check for params to be \Magento\Framework\Object - by using object we protect from
         * params being forged and contain data from user frontend input
         */
        if ($params instanceof \Magento\Framework\Object) {
            return $params;
        }
        return new \Magento\Framework\Object();
    }

    /**
     * Returns file info array if we need to get file from already existing file.
     * Or returns null, if we need to get file from uploaded array.
     *
     * @return null|array
     */
    protected function _getCurrentConfigFileInfo()
    {
        $option = $this->getOption();
        $optionId = $option->getId();
        $processingParams = $this->_getProcessingParams();
        $buyRequest = $this->getRequest();

        // Check maybe restore file from config requested
        $optionActionKey = 'options_' . $optionId . '_file_action';
        if ($buyRequest->getData($optionActionKey) == 'save_old') {
            $fileInfo = array();
            $currentConfig = $processingParams->getCurrentConfig();
            if ($currentConfig) {
                $fileInfo = $currentConfig->getData('options/' . $optionId);
            }
            return $fileInfo;
        }
        return null;
    }

    /**
     * Validate user input for option
     *
     * @param array $values All product option values, i.e. array (option_id => mixed, option_id => mixed...)
     * @return $this
     * @throws Exception
     */
    public function validateUserValue($values)
    {
        $this->_checkoutSession->setUseNotice(false);

        $this->setIsValid(true);
        $option = $this->getOption();

        /*
         * Check whether we receive uploaded file or restore file by: reorder/edit configuration or
         * previous configuration with no newly uploaded file
         */
        $fileInfo = null;
        if (isset($values[$option->getId()]) && is_array($values[$option->getId()])) {
            // Legacy style, file info comes in array with option id index
            $fileInfo = $values[$option->getId()];
        } else {
            /*
             * New recommended style - file info comes in request processing parameters and we
             * sure that this file info originates from Magento, not from manually formed POST request
             */
            $fileInfo = $this->_getCurrentConfigFileInfo();
        }
        if ($fileInfo !== null) {
            try {
                $value = $this->validatorInfo->setUseQuotePath($this->getUseQuotePath())
                    ->validate($fileInfo, $option) ? $fileInfo : null;
                $this->setUserValue($value);
                return $this;
            } catch (Exception $exception) {
                $this->setIsValid(false);
                throw $exception;
            }
        }

        // Process new uploaded file
        try {
            $value = $this->validatorFile->setProduct($this->getProduct())
                ->validate($this->_getProcessingParams(), $option);
            $this->setUserValue($value);
        } catch (File\LargeSizeException $largeSizeException) {
            $this->setIsValid(false);
            throw new Exception($largeSizeException->getMessage());
        } catch (File\OptionRequiredException $e) {
            switch ($this->getProcessMode()) {
                case \Magento\Catalog\Model\Product\Type\AbstractType::PROCESS_MODE_FULL:
                    throw new Exception(__('Please specify the product\'s required option(s).'));
                    break;
                default:
                    $this->setUserValue(null);
                    break;
            }
        } catch (File\RunValidationException $e) {
            $this->setUserValue(null);
        } catch (File\Exception $e) {
            $this->setIsValid(false);
            throw new Exception($e->getMessage());
        } catch (\Exception $e) {
            if ($this->getSkipCheckRequiredOption()) {
                $this->setUserValue(null);
            } else {
                throw new Exception($e->getMessage());
            }
        }
        return $this;
    }

    /**
     * Prepare option value for cart
     *
     * @return string|null Prepared option value
     */
    public function prepareForCart()
    {
        $option = $this->getOption();
        $optionId = $option->getId();
        $buyRequest = $this->getRequest();

        // Prepare value and fill buyRequest with option
        $requestOptions = $buyRequest->getOptions();
        if ($this->getIsValid() && $this->getUserValue() !== null) {
            $value = $this->getUserValue();

            // Save option in request, because we have no $_FILES['options']
            $requestOptions[$this->getOption()->getId()] = $value;
            $result = serialize($value);
        } else {
            /*
             * Clear option info from request, so it won't be stored in our db upon
             * unsuccessful validation. Otherwise some bad file data can happen in buyRequest
             * and be used later in reorders and reconfigurations.
             */
            if (is_array($requestOptions)) {
                unset($requestOptions[$this->getOption()->getId()]);
            }
            $result = null;
        }
        $buyRequest->setOptions($requestOptions);

        // Clear action key from buy request - we won't need it anymore
        $optionActionKey = 'options_' . $optionId . '_file_action';
        $buyRequest->unsetData($optionActionKey);

        return $result;
    }

    /**
     * Return formatted option value for quote option
     *
     * @param string $optionValue Prepared for cart option value
     * @return string
     */
    public function getFormattedOptionValue($optionValue)
    {
        if ($this->_formattedOptionValue === null) {
            try {
                $value = unserialize($optionValue);

                $customOptionUrlParams = $this->getCustomOptionUrlParams() ? $this->getCustomOptionUrlParams() : array(
                    'id' => $this->getConfigurationItemOption()->getId(),
                    'key' => $value['secret_key']
                );

                $value['url'] = array('route' => $this->_customOptionDownloadUrl, 'params' => $customOptionUrlParams);

                $this->_formattedOptionValue = $this->_getOptionHtml($value);
                $this->getConfigurationItemOption()->setValue(serialize($value));
                return $this->_formattedOptionValue;
            } catch (\Exception $e) {
                return $optionValue;
            }
        }
        return $this->_formattedOptionValue;
    }

    /**
     * Format File option html
     *
     * @param string|array $optionValue Serialized string of option data or its data array
     * @return string
     * @throws Exception
     */
    protected function _getOptionHtml($optionValue)
    {
        $value = $this->_unserializeValue($optionValue);
        try {
            $sizes = $this->prepareSize($value);

            $urlRoute = !empty($value['url']['route']) ? $value['url']['route'] : '';
            $urlParams = !empty($value['url']['params']) ? $value['url']['params'] : '';
            $title = !empty($value['title']) ? $value['title'] : '';

            return sprintf(
                '<a href="%s" target="_blank">%s</a> %s',
                $this->_getOptionDownloadUrl($urlRoute, $urlParams),
                $this->_escaper->escapeHtml($title),
                $sizes
            );
        } catch (\Exception $e) {
            throw new Exception(__("The file options format is not valid."));
        }
    }

    /**
     * Create a value from a storable representation
     *
     * @param string|array $value
     * @return array
     */
    protected function _unserializeValue($value)
    {
        if (is_array($value)) {
            return $value;
        } elseif (is_string($value) && !empty($value)) {
            return unserialize($value);
        } else {
            return array();
        }
    }

    /**
     * Return printable option value
     *
     * @param string $optionValue Prepared for cart option value
     * @return string
     */
    public function getPrintableOptionValue($optionValue)
    {
        return strip_tags($this->getFormattedOptionValue($optionValue));
    }

    /**
     * Return formatted option value ready to edit, ready to parse
     *
     * @param string $optionValue Prepared for cart option value
     * @return string
     */
    public function getEditableOptionValue($optionValue)
    {
        try {
            $value = unserialize($optionValue);
            return sprintf(
                '%s [%d]',
                $this->_escaper->escapeHtml($value['title']),
                $this->getConfigurationItemOption()->getId()
            );
        } catch (\Exception $e) {
            return $optionValue;
        }
    }

    /**
     * Parse user input value and return cart prepared value
     *
     * @param string $optionValue
     * @param array $productOptionValues Values for product option
     * @return string|null
     */
    public function parseOptionValue($optionValue, $productOptionValues)
    {
        // search quote item option Id in option value
        if (preg_match('/\[([0-9]+)\]/', $optionValue, $matches)) {
            $confItemOptionId = $matches[1];
            $option = $this->_itemOptionFactory->create()->load($confItemOptionId);
            try {
                unserialize($option->getValue());
                return $option->getValue();
            } catch (\Exception $e) {
                return null;
            }
        } else {
            return null;
        }
    }

    /**
     * Prepare option value for info buy request
     *
     * @param string $optionValue
     * @return string|null
     */
    public function prepareOptionValueForRequest($optionValue)
    {
        try {
            $result = unserialize($optionValue);
            return $result;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Quote item to order item copy process
     *
     * @return $this
     */
    public function copyQuoteToOrder()
    {
        $quoteOption = $this->getConfigurationItemOption();
        try {
            $value = unserialize($quoteOption->getValue());
            if (!isset($value['quote_path'])) {
                throw new \Exception();
            }
            $quotePath = $value['quote_path'];
            $orderPath = $value['order_path'];

            if (!$this->_rootDirectory->isFile($quotePath) || !$this->_rootDirectory->isReadable($quotePath)) {
                throw new \Exception();
            }
            $this->_coreFileStorageDatabase->copyFile(
                $this->_rootDirectory->getAbsolutePath($quotePath),
                $this->_rootDirectory->getAbsolutePath($orderPath)
            );
        } catch (\Exception $e) {
            return $this;
        }
        return $this;
    }

    /**
     * Set url to custom option download controller
     *
     * @param string $url
     * @return $this
     */
    public function setCustomOptionDownloadUrl($url)
    {
        $this->_customOptionDownloadUrl = $url;
        return $this;
    }

    /**
     * Return URL for option file download
     *
     * @param string|null $route
     * @param array|null $params
     * @return string
     */
    protected function _getOptionDownloadUrl($route, $params)
    {
        return $this->_urlBuilder->getUrl($route, $params);
    }

    /**
     * @param array $value
     * @return string
     */
    protected function prepareSize($value)
    {
        $sizes = '';
        if (!empty($value['width']) && !empty($value['height']) && $value['width'] > 0 && $value['height'] > 0) {
            $sizes = $value['width'] . ' x ' . $value['height'] . ' ' . __('px.');
            return array($value, $sizes);
        }
        return $sizes;
    }
}
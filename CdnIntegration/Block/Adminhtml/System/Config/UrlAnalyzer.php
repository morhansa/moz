<?php
namespace MagoArab\CdnIntegration\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;

class UrlAnalyzer extends Field
{
    /**
     * @var string
     */
    protected $_template = 'MagoArab_CdnIntegration::system/config/url_analyzer.phtml';

    /**
     * @param Context $context
     * @param array $data
     */
    public function __construct(
        Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Remove scope label
     *
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    /**
     * Return element html
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    /**
     * Return ajax url for analyzer
     *
     * @return string
     */
    public function getAjaxUrl()
    {
        return $this->getUrl('magoarab_cdn/cdn/analyzeUrls');
    }

    /**
     * Generate button html
     *
     * @return string
     */
    public function getButtonHtml()
    {
        $button = $this->getLayout()->createBlock(
            \Magento\Backend\Block\Widget\Button::class
        )->setData(
            [
                'id' => 'analyze_urls_button',
                'label' => __('Analyze Storefront'),
            ]
        );

        return $button->toHtml();
    }
    
    /**
     * Return ajax url for upload to GitHub
     *
     * @return string
     */
    public function getUploadUrl()
    {
        return $this->getUrl('magoarab_cdn/cdn/uploadToGithub');
    }
    
    /**
     * Return direct ajax url for upload to GitHub
     * This new method uses a different controller for more reliability
     * Make sure to match the case of the controller directory
     *
     * @return string
     */
    public function getDirectUploadUrl()
    {
        // Use exact case to match Controller/Adminhtml/Upload/ToGithub.php
        return $this->getUrl('magoarab_cdn/Upload/ToGithub');
    }
    
    /**
     * Get store base URL
     * 
     * @return string
     */
    public function getStoreUrl()
    {
        return $this->_storeManager->getStore()->getBaseUrl();
    }
}
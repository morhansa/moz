<?php
namespace MagoArab\CdnIntegration\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;

class EnhancedUrlAnalyzer extends Field
{
    /**
     * @var string
     */
    protected $_template = 'MagoArab_CdnIntegration::system/config/enhanced_url_analyzer.phtml';

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
     * Return ajax url for regular analysis
     *
     * @return string
     */
    public function getAjaxUrl()
    {
        return $this->getUrl('magoarab_cdn/cdn/analyzeUrls');
    }

    /**
     * Return ajax url for advanced analysis
     *
     * @return string
     */
    public function getAdvancedAjaxUrl()
    {
        return $this->getUrl('magoarab_cdn/cdn/advancedAnalyze');
    }
    
    /**
     * Return ajax url for direct URL analysis
     *
     * @return string
     */
    public function getDirectAnalysisUrl()
    {
        return $this->getUrl('magoarab_cdn/cdn/directAnalyzeUrls');
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
     * Get store base URL
     * 
     * @return string
     */
    public function getStoreUrl()
    {
        return $this->_storeManager->getStore()->getBaseUrl();
    }

    /**
     * Generate button html for regular analysis
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
                'label' => __('Quick Analyze'),
            ]
        );

        return $button->toHtml();
    }
    
    /**
     * Generate button html for advanced analysis
     *
     * @return string
     */
    public function getAdvancedButtonHtml()
    {
        $button = $this->getLayout()->createBlock(
            \Magento\Backend\Block\Widget\Button::class
        )->setData(
            [
                'id' => 'advanced_analyze_button',
                'label' => __('Deep Analyze (Multiple Pages)'),
                'class' => 'action-secondary'
            ]
        );

        return $button->toHtml();
    }
    
    /**
     * Generate upload button html
     *
     * @return string
     */
    public function getUploadButtonHtml()
    {
        $button = $this->getLayout()->createBlock(
            \Magento\Backend\Block\Widget\Button::class
        )->setData(
            [
                'id' => 'upload_to_github',
                'label' => __('Upload to GitHub'),
                'class' => 'action-primary'
            ]
        );

        return $button->toHtml();
    }
}
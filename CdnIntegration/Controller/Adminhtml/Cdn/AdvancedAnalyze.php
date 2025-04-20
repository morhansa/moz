<?php
namespace MagoArab\CdnIntegration\Controller\Adminhtml\Cdn;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use MagoArab\CdnIntegration\Helper\Data as Helper;
use MagoArab\CdnIntegration\Model\AdvancedUrlAnalyzer;

class AdvancedAnalyze extends Action
{
    /**
     * Authorization level
     */
    const ADMIN_RESOURCE = 'MagoArab_CdnIntegration::config';

    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var Helper
     */
    protected $helper;
    
    /**
     * @var AdvancedUrlAnalyzer
     */
    protected $urlAnalyzer;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param Helper $helper
     * @param AdvancedUrlAnalyzer $urlAnalyzer
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Helper $helper,
        AdvancedUrlAnalyzer $urlAnalyzer
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->helper = $helper;
        $this->urlAnalyzer = $urlAnalyzer;
    }

    /**
     * Advanced analyze URLs
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();
        
        try {
            if (!$this->helper->isEnabled()) {
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('CDN Integration is disabled.')
                ]);
            }
            
            $storeUrl = $this->getRequest()->getParam('store_url');
            $maxPages = (int)$this->getRequest()->getParam('max_pages', 5);
            
            if (empty($storeUrl)) {
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('Store URL is required.')
                ]);
            }
            
            $this->helper->log("Starting advanced URL analysis for: {$storeUrl}", 'info');
            
            // Execute the advanced analysis
            $urls = $this->urlAnalyzer->analyze($storeUrl, $maxPages);
            
            if (empty($urls)) {
                $this->helper->log("No URLs found in advanced analysis", 'warning');
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('No suitable URLs found to analyze.')
                ]);
            }
            
            $this->helper->log("Advanced analysis complete, found " . count($urls) . " URLs", 'info');
            
            $this->messageManager->addSuccessMessage(
                __('Advanced analysis found %1 unique static and media URLs.', count($urls))
            );
            
            return $resultJson->setData([
                'success' => true,
                'urls' => $urls,
                'message' => __('URL analysis completed with %1 URLs found.', count($urls))
            ]);
        } catch (\Exception $e) {
            $this->helper->log('Error in AdvancedAnalyze::execute: ' . $e->getMessage(), 'error');
            $this->messageManager->addExceptionMessage($e, __('An error occurred during advanced URL analysis.'));
            
            return $resultJson->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}
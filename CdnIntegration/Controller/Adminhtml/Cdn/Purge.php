<?php
namespace MagoArab\CdnIntegration\Controller\Adminhtml\Cdn;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use MagoArab\CdnIntegration\Model\Jsdelivr\Api as JsdelivrApi;
use MagoArab\CdnIntegration\Helper\Data as Helper;

class Purge extends Action
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
     * @var JsdelivrApi
     */
    protected $jsdelivrApi;

    /**
     * @var Helper
     */
    protected $helper;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param JsdelivrApi $jsdelivrApi
     * @param Helper $helper
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        JsdelivrApi $jsdelivrApi,
        Helper $helper
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->jsdelivrApi = $jsdelivrApi;
        $this->helper = $helper;
    }

    /**
     * Purge jsDelivr CDN cache
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
            
            $success = $this->jsdelivrApi->purgeAll();
            
            if ($success) {
                $this->messageManager->addSuccessMessage(__('jsDelivr CDN cache has been purged successfully.'));
                return $resultJson->setData([
                    'success' => true,
                    'message' => __('jsDelivr CDN cache has been purged successfully.')
                ]);
            } else {
                $this->messageManager->addErrorMessage(__('Failed to purge jsDelivr CDN cache. Please check the logs for details.'));
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('Failed to purge jsDelivr CDN cache. Please check the logs for details.')
                ]);
            }
        } catch (\Exception $e) {
            $this->helper->log('Error purging jsDelivr CDN cache: ' . $e->getMessage(), 'error');
            $this->messageManager->addExceptionMessage($e, __('An error occurred while purging the jsDelivr CDN cache.'));
            
            return $resultJson->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}
<?php
namespace MagoArab\CdnIntegration\Controller\Adminhtml\Cdn;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use MagoArab\CdnIntegration\Model\Github\Api as GithubApi;
use MagoArab\CdnIntegration\Helper\Data as Helper;

class TestConnection extends Action
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
     * @var GithubApi
     */
    protected $githubApi;

    /**
     * @var Helper
     */
    protected $helper;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param GithubApi $githubApi
     * @param Helper $helper
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        GithubApi $githubApi,
        Helper $helper
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->githubApi = $githubApi;
        $this->helper = $helper;
    }

    /**
     * Test GitHub connection
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
            
            // Required fields validation
            $username = $this->helper->getGithubUsername();
            $repository = $this->helper->getGithubRepository();
            $token = $this->helper->getGithubToken();
            
            if (empty($username)) {
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('GitHub Username is required.')
                ]);
            }
            
            if (empty($repository)) {
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('GitHub Repository is required.')
                ]);
            }
            
            if (empty($token)) {
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('GitHub Personal Access Token is required.')
                ]);
            }
            
            // Test the connection
            $success = $this->githubApi->testConnection();
            
            if ($success) {
                $this->messageManager->addSuccessMessage(__('GitHub connection test successful.'));
                return $resultJson->setData([
                    'success' => true,
                    'message' => __('GitHub connection test successful. Your credentials are correct and you have proper access to the repository.')
                ]);
            } else {
                $this->messageManager->addErrorMessage(__('GitHub connection test failed. Please check your credentials.'));
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('GitHub connection test failed. Please check if your username, repository name, and personal access token are correct. Also ensure the token has proper permissions (repo scope).')
                ]);
            }
        } catch (\Exception $e) {
            $this->helper->log('Error testing GitHub connection: ' . $e->getMessage(), 'error');
            $this->messageManager->addExceptionMessage($e, __('An error occurred while testing GitHub connection.'));
            
            return $resultJson->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}
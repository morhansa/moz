<?php
namespace MagoArab\CdnIntegration\Controller\Adminhtml\Cdn;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use MagoArab\CdnIntegration\Helper\Data as Helper;
use MagoArab\CdnIntegration\Model\Github\Api as GithubApi;

class TestGithub extends Action
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
     * @var GithubApi
     */
    protected $githubApi;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param Helper $helper
     * @param GithubApi $githubApi
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Helper $helper,
        GithubApi $githubApi
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->helper = $helper;
        $this->githubApi = $githubApi;
    }

    /**
     * Test GitHub connection and configuration
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();
        
        try {
            $debug = [
                'github_api_class' => get_class($this->githubApi),
                'helper_class' => get_class($this->helper),
                'github_settings' => [
                    'username' => $this->helper->getGithubUsername(),
                    'repository' => $this->helper->getGithubRepository(),
                    'branch' => $this->helper->getGithubBranch()
                ],
                'module_enabled' => $this->helper->isEnabled(),
                'debug_enabled' => $this->helper->isDebugEnabled()
            ];
            
            // Test connection
            $testResult = [
                'connection_success' => false,
                'message' => 'Not tested'
            ];
            
            if ($this->helper->isEnabled()) {
                $connectionTest = $this->githubApi->testConnection();
                $testResult = [
                    'connection_success' => $connectionTest,
                    'message' => $connectionTest ? 'Connection successful' : 'Connection failed'
                ];
            }
            
            return $resultJson->setData([
                'success' => true,
                'debug_info' => $debug,
                'test_result' => $testResult
            ]);
        } catch (\Exception $e) {
            return $resultJson->setData([
                'success' => false,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
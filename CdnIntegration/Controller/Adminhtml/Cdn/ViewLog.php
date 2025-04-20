<?php
namespace MagoArab\CdnIntegration\Controller\Adminhtml\Cdn;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use MagoArab\CdnIntegration\Helper\Data as Helper;

class ViewLog extends Action
{
    /**
     * Authorization level
     */
    const ADMIN_RESOURCE = 'MagoArab_CdnIntegration::config';

    /**
     * Log file path
     */
    const LOG_FILE = '/var/log/magoarab_cdn_integration.log';

    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var Helper
     */
    protected $helper;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param Helper $helper
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Helper $helper
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->helper = $helper;
    }

    /**
     * View the CDN integration log
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();
        
        try {
            $logFile = BP . self::LOG_FILE;
            $content = '';
            
            if (file_exists($logFile)) {
                // Get the last 1000 lines of the log file
                $lines = $this->getTailOfFile($logFile, 1000);
                $content = implode("\n", $lines);
                
                return $resultJson->setData([
                    'success' => true,
                    'content' => $content
                ]);
            } else {
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('Log file does not exist.')
                ]);
            }
        } catch (\Exception $e) {
            return $resultJson->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Get the last n lines of a file
     *
     * @param string $filePath
     * @param int $lines
     * @return array
     */
    protected function getTailOfFile($filePath, $lines = 100)
    {
        $result = [];
        
        if (!file_exists($filePath)) {
            return $result;
        }
        
        $file = new \SplFileObject($filePath, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();
        
        $startLine = ($totalLines > $lines) ? $totalLines - $lines : 0;
        
        $file->seek($startLine);
        
        while (!$file->eof()) {
            $line = $file->current();
            $file->next();
            
            if ($line !== false) {
                $result[] = htmlspecialchars($line);
            }
        }
        
        return $result;
    }
}
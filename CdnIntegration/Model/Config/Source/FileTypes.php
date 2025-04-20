<?php
namespace MagoArab\CdnIntegration\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

class FileTypes implements ArrayInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'css', 'label' => __('CSS Files')],
            ['value' => 'js', 'label' => __('JavaScript Files')],
            ['value' => 'png', 'label' => __('PNG Images')],
            ['value' => 'jpg', 'label' => __('JPG Images')],
            ['value' => 'jpeg', 'label' => __('JPEG Images')],
            ['value' => 'gif', 'label' => __('GIF Images')],
            ['value' => 'svg', 'label' => __('SVG Images')],
            ['value' => 'woff', 'label' => __('WOFF Fonts')],
            ['value' => 'woff2', 'label' => __('WOFF2 Fonts')],
            ['value' => 'ttf', 'label' => __('TTF Fonts')],
            ['value' => 'eot', 'label' => __('EOT Fonts')]
        ];
    }

    /**
     * Get options in "key-value" format
     *
     * @return array
     */
    public function toArray()
    {
        $result = [];
        foreach ($this->toOptionArray() as $option) {
            $result[$option['value']] = $option['label'];
        }
        return $result;
    }
}
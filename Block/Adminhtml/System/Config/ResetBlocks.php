<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class ResetBlocks extends Field
{
    /**
     * @inheritdoc
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        if (!$this->_authorization->isAllowed('Hachitechs_Hideguard::reset')) {
            return '';
        }
        return $this->setTemplate('Hachitechs_Hideguard::system/config/reset-blocks.phtml')->toHtml();
    }

    /**
     * Build the reset endpoint and the currently selected configuration scope.
     *
     * @return array
     */
    public function getResetConfig(): array
    {
        return [
            'url' => $this->getUrl('hideguard/blocks/reset'),
            'store' => (string)$this->getRequest()->getParam('store', ''),
            'website' => (string)$this->getRequest()->getParam('website', ''),
        ];
    }
}

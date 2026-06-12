import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/pos/Modal';
import { CustomerAttachBody } from './CustomerAttachPanel';

export interface CustomerSearchModalProps {
  isOpen: boolean;
  onClose: () => void;
  tenantId: string;
  companyId: string;
  terminalId: string;
  staleThresholdMinutes?: number;
  onAccountPaymentComplete?: () => void;
}

export function CustomerSearchModal({
  isOpen,
  onClose,
  tenantId,
  companyId,
  terminalId,
  staleThresholdMinutes = 30,
  onAccountPaymentComplete,
}: CustomerSearchModalProps) {
  const { t } = useTranslation('pos');
  const [isProcessing, setIsProcessing] = useState(false);

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={t('customer.modalTitle')}
      size="md"
      closable={!isProcessing}
    >
      {/* stable min-height so search/create/selected states don't resize the modal */}
      <div data-testid="customer-modal-shell" className="min-h-[420px]">
        <CustomerAttachBody
          tenantId={tenantId}
          companyId={companyId}
          terminalId={terminalId}
          staleThresholdMinutes={staleThresholdMinutes}
          onProcessingChange={setIsProcessing}
          onSelected={onClose}
          onAccountPaymentComplete={() => {
            onClose();
            onAccountPaymentComplete?.();
          }}
        />
      </div>
    </Modal>
  );
}

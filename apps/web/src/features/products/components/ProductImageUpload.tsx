import { useState, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { Upload, AlertCircle } from 'lucide-react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { uploadProductImage } from '../api/productImages'
import { getErrorMessage } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface ProductImageUploadProps {
  productId: string
  onUploadSuccess?: () => void
}

const MAX_FILE_SIZE = 5 * 1024 * 1024 // 5MB
const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif']

export function ProductImageUpload({ productId, onUploadSuccess }: ProductImageUploadProps) {
  const { t } = useTranslation(['products', 'common'])
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const fileInputRef = useRef<HTMLInputElement>(null)
  const [dragActive, setDragActive] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const uploadMutation = useMutation({
    mutationFn: (file: File) => uploadProductImage(productId, file),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['product-images', productId]) }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['product', productId]) }),
      ])
      void tenantId
      void companyId
      setError(null)
      if (onUploadSuccess) {
        onUploadSuccess()
      }
      // Reset file input
      if (fileInputRef.current) {
        fileInputRef.current.value = ''
      }
    },
    onError: (err) => {
      setError(getErrorMessage(err))
    },
  })

  const validateFile = (file: File): string | null => {
    if (file.size > MAX_FILE_SIZE) {
      return t('products:images.errors.fileTooLarge', { max: '5MB' })
    }

    if (!ALLOWED_TYPES.includes(file.type)) {
      return t('products:images.errors.invalidType')
    }

    return null
  }

  const handleFile = (file: File) => {
    const validationError = validateFile(file)
    if (validationError) {
      setError(validationError)
      return
    }

    uploadMutation.mutate(file)
  }

  const handleFileInput = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (file) {
      handleFile(file)
    }
  }

  const handleDrag = (e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    if (e.type === 'dragenter' || e.type === 'dragover') {
      setDragActive(true)
    } else if (e.type === 'dragleave') {
      setDragActive(false)
    }
  }

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    setDragActive(false)

    const file = e.dataTransfer.files?.[0]
    if (file) {
      handleFile(file)
    }
  }

  const handleClick = () => {
    fileInputRef.current?.click()
  }

  return (
    <div className="space-y-3">
      <div
        className={`
          relative cursor-pointer rounded-lg border-2 border-dashed p-8 text-center transition-colors
          ${dragActive ? `${colorTokens.intent.primary.borderFocus} ${colorTokens.intent.primary.bgSubtle}` : `${colorTokens.border.default} hover:${colorTokens.border.strong}`}
          ${uploadMutation.isPending ? 'cursor-not-allowed opacity-50' : ''}
        `}
        onDragEnter={handleDrag}
        onDragLeave={handleDrag}
        onDragOver={handleDrag}
        onDrop={handleDrop}
        onClick={uploadMutation.isPending ? undefined : handleClick}
      >
        <input
          ref={fileInputRef}
          type="file"
          className="hidden"
          accept="image/jpeg,image/png,image/webp,image/gif"
          onChange={handleFileInput}
          disabled={uploadMutation.isPending}
        />

        <div className="space-y-2">
          <div className="flex justify-center">
            <Upload className={`h-10 w-10 ${dragActive ? `${colorTokens.intent.primary.textSubtle}` : `${colorTokens.text.disabled}`}`} />
          </div>

          {uploadMutation.isPending ? (
            <p className={`text-sm ${colorTokens.text.muted}`}>{t('products:images.uploading')}</p>
          ) : (
            <>
              <p className={`text-sm ${colorTokens.text.muted}`}>
                {t('products:images.dragDropOrClick')}
              </p>
              <p className={`text-xs ${colorTokens.text.subtle}`}>
                {t('products:images.supportedFormatsDetail')}
              </p>
            </>
          )}
        </div>
      </div>

      {error && (
        <div className={`flex items-start gap-2 rounded-md border ${colorTokens.intent.danger.borderSubtle} ${colorTokens.intent.danger.bgSubtle} p-3`}>
          <AlertCircle className={`h-4 w-4 flex-shrink-0 ${colorTokens.intent.danger.text} mt-0.5`} />
          <p className={`text-sm ${colorTokens.intent.danger.textStronger}`}>{error}</p>
        </div>
      )}
    </div>
  )
}

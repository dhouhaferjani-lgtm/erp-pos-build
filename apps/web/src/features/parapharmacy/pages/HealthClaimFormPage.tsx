import { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TranslationEditor, Translation } from '../components/TranslationEditor';
import {
  fetchHealthClaim,
  createHealthClaim,
  updateHealthClaim,
  type CreateHealthClaimInput,
} from '../api/healthClaimApi';
import { toast } from 'sonner';

export function HealthClaimFormPage() {
  const { t } = useTranslation(['common', 'parapharmacy']);
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  const isEdit = !!id && id !== 'new';

  const [claimType, setClaimType] = useState<
    'function' | 'reduction_of_disease_risk' | 'development_and_health'
  >('function');
  const [slug, setSlug] = useState('');
  const [regulatoryStatus, setRegulatoryStatus] = useState<
    'approved' | 'pending' | 'rejected'
  >('approved');
  const [efsaReference, setEfsaReference] = useState('');
  const [fdaReference, setFdaReference] = useState('');
  const [countryRestrictions, setCountryRestrictions] = useState('');
  const [requiresDisclaimer, setRequiresDisclaimer] = useState(false);
  const [translations, setTranslations] = useState<Translation[]>([
    { locale: 'en', claim: '', disclaimer_text: '' },
  ]);

  const { data: healthClaim, isLoading } = useQuery({
    queryKey: ['parapharmacy', 'health-claims', id],
    queryFn: () => fetchHealthClaim(id!),
    enabled: isEdit,
  });

  useEffect(() => {
    if (healthClaim) {
      setClaimType(healthClaim.claim_type);
      setSlug(healthClaim.slug);
      setRegulatoryStatus(healthClaim.regulatory_status);
      setEfsaReference(healthClaim.efsa_reference || '');
      setFdaReference(healthClaim.fda_reference || '');
      setCountryRestrictions(
        healthClaim.country_restrictions?.join(', ') || ''
      );
      setRequiresDisclaimer(healthClaim.requires_disclaimer);

      setTranslations([
        {
          locale: 'en',
          claim: healthClaim.claim,
          disclaimer_text: healthClaim.disclaimer_text || '',
        },
      ]);
    }
  }, [healthClaim]);

  const createMutation = useMutation({
    mutationFn: createHealthClaim,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['parapharmacy', 'health-claims'] });
      toast.success(t('parapharmacy:healthClaimCreated'));
      navigate('/parapharmacy/health-claims');
    },
    onError: (error: any) => {
      const message =
        error?.response?.data?.error?.message ||
        t('parapharmacy:createHealthClaimError');
      toast.error(message);
    },
  });

  const updateMutation = useMutation({
    mutationFn: (data: CreateHealthClaimInput) => updateHealthClaim(id!, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['parapharmacy', 'health-claims'] });
      toast.success(t('parapharmacy:healthClaimUpdated'));
      navigate('/parapharmacy/health-claims');
    },
    onError: (error: any) => {
      const message =
        error?.response?.data?.error?.message ||
        t('parapharmacy:updateHealthClaimError');
      toast.error(message);
    },
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();

    const data: CreateHealthClaimInput = {
      claim_type: claimType,
      slug,
      regulatory_status: regulatoryStatus,
      efsa_reference: efsaReference || null,
      fda_reference: fdaReference || null,
      country_restrictions: countryRestrictions
        ? countryRestrictions.split(',').map((c) => c.trim())
        : null,
      requires_disclaimer: requiresDisclaimer,
      translations: translations.map((t) => ({
        id: t.id,
        locale: t.locale,
        claim: t.claim as string,
        disclaimer_text: (t.disclaimer_text as string) || null,
      })),
    };

    if (isEdit) {
      updateMutation.mutate(data);
    } else {
      createMutation.mutate(data);
    }
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        {t('common:loading')}
      </div>
    );
  }

  return (
    <div className="space-y-6 max-w-4xl">
      <div className="flex items-center gap-4">
        <Button
          variant="ghost"
          size="sm"
          onClick={() => navigate('/parapharmacy/health-claims')}
        >
          <ArrowLeft className="h-4 w-4 mr-2" />
          {t('common:back')}
        </Button>
        <div>
          <h1 className="text-3xl font-bold">
            {isEdit
              ? t('parapharmacy:editHealthClaim')
              : t('parapharmacy:addHealthClaim')}
          </h1>
        </div>
      </div>

      <form onSubmit={handleSubmit} className="space-y-6">
        <Card>
          <CardHeader>
            <CardTitle>{t('parapharmacy:basicInformation')}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <Label htmlFor="claim_type">
                  {t('parapharmacy:claimType')} <span className="text-destructive">*</span>
                </Label>
                <Select
                  value={claimType}
                  onValueChange={(
                    value: 'function' | 'reduction_of_disease_risk' | 'development_and_health'
                  ) => setClaimType(value)}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="function">
                      {t('parapharmacy:claimType.function')}
                    </SelectItem>
                    <SelectItem value="reduction_of_disease_risk">
                      {t('parapharmacy:claimType.reduction_of_disease_risk')}
                    </SelectItem>
                    <SelectItem value="development_and_health">
                      {t('parapharmacy:claimType.development_and_health')}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div>
                <Label htmlFor="slug">
                  {t('parapharmacy:slug')} <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="slug"
                  value={slug}
                  onChange={(e) => setSlug(e.target.value)}
                  required
                  placeholder="supports-immune-function"
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <Label htmlFor="regulatory_status">
                  {t('parapharmacy:regulatoryStatus')}{' '}
                  <span className="text-destructive">*</span>
                </Label>
                <Select
                  value={regulatoryStatus}
                  onValueChange={(value: 'approved' | 'pending' | 'rejected') =>
                    setRegulatoryStatus(value)
                  }
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="approved">
                      {t('parapharmacy:regulatoryStatus.approved')}
                    </SelectItem>
                    <SelectItem value="pending">
                      {t('parapharmacy:regulatoryStatus.pending')}
                    </SelectItem>
                    <SelectItem value="rejected">
                      {t('parapharmacy:regulatoryStatus.rejected')}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div>
                <Label htmlFor="efsa_reference">{t('parapharmacy:efsaReference')}</Label>
                <Input
                  id="efsa_reference"
                  value={efsaReference}
                  onChange={(e) => setEfsaReference(e.target.value)}
                  placeholder="EFSA-Q-2008-123"
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <Label htmlFor="fda_reference">{t('parapharmacy:fdaReference')}</Label>
                <Input
                  id="fda_reference"
                  value={fdaReference}
                  onChange={(e) => setFdaReference(e.target.value)}
                  placeholder="FDA-2008-N-0453"
                />
              </div>

              <div>
                <Label htmlFor="country_restrictions">
                  {t('parapharmacy:countryRestrictions')}
                </Label>
                <Input
                  id="country_restrictions"
                  value={countryRestrictions}
                  onChange={(e) => setCountryRestrictions(e.target.value)}
                  placeholder="FR, DE, IT (comma-separated)"
                />
                <p className="text-xs text-muted-foreground mt-1">
                  {t('parapharmacy:countryRestrictionsHelp')}
                </p>
              </div>
            </div>

            <div className="flex items-center space-x-2">
              <Checkbox
                id="requires_disclaimer"
                checked={requiresDisclaimer}
                onCheckedChange={(checked) => setRequiresDisclaimer(checked as boolean)}
              />
              <Label htmlFor="requires_disclaimer" className="font-normal">
                {t('parapharmacy:requiresDisclaimer')}
              </Label>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{t('parapharmacy:translations')}</CardTitle>
          </CardHeader>
          <CardContent>
            <TranslationEditor
              translations={translations}
              onChange={setTranslations}
              fields={[
                {
                  name: 'claim',
                  label: t('parapharmacy:claim'),
                  required: true,
                  multiline: true,
                },
                {
                  name: 'disclaimer_text',
                  label: t('parapharmacy:disclaimerText'),
                  required: false,
                  multiline: true,
                },
              ]}
            />
          </CardContent>
        </Card>

        <div className="flex justify-end gap-4">
          <Button
            type="button"
            variant="outline"
            onClick={() => navigate('/parapharmacy/health-claims')}
          >
            {t('common:cancel')}
          </Button>
          <Button
            type="submit"
            disabled={createMutation.isPending || updateMutation.isPending}
          >
            {createMutation.isPending || updateMutation.isPending
              ? t('common:saving')
              : t('common:save')}
          </Button>
        </div>
      </form>
    </div>
  );
}

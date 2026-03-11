import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, Pencil } from 'lucide-react'
import { Button } from '@/components/atoms'
import { Spinner } from '@/components/atoms/Spinner/Spinner'
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/molecules/Tabs/Tabs'

import { useProgram } from '../hooks/usePrograms'
import { ProgramStatusBadge } from '../components/ProgramStatusBadge'
import { EarningRulesTab } from '../components/EarningRulesTab'
import { RewardsTab } from '../components/RewardsTab'
import { TiersTab } from '../components/TiersTab'
import { StampCardsTab } from '../components/StampCardsTab'

export function ProgramDetailPage() {
  const { t } = useTranslation(['loyalty', 'common'])
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const { data: program, isLoading } = useProgram(id ?? '')

  if (isLoading) {
    return (
      <div className="flex justify-center py-12">
        <Spinner />
      </div>
    )
  }

  if (!program) {
    return <div className="text-center py-12 text-gray-500">{t('common:notFound')}</div>
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <button
            type="button"
            onClick={() => navigate('/pos/loyalty/programs')}
            className="p-2 rounded-lg hover:bg-gray-100"
          >
            <ArrowLeft className="w-5 h-5" />
          </button>
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-bold text-gray-900">{program.name}</h1>
              <ProgramStatusBadge status={program.status} />
            </div>
            <p className="text-sm text-gray-500 mt-1">
              {t(`loyalty:programTypes.${program.program_type}`)}
              {program.currency ? ` \u00b7 ${program.currency}` : ''}
            </p>
          </div>
        </div>
        <Button variant="secondary" onClick={() => navigate(`/pos/loyalty/programs/${id}/edit`)}>
          <Pencil className="w-4 h-4 mr-2" />
          {t('loyalty:programs.edit')}
        </Button>
      </div>

      {/* Overview card */}
      <div className="bg-white rounded-lg border border-gray-200 p-6">
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div>
            <p className="text-xs font-medium text-gray-500 uppercase">{t('loyalty:fields.startDate')}</p>
            <p className="mt-1 text-sm text-gray-900">
              {program.start_date ? new Date(program.start_date).toLocaleDateString() : '-'}
            </p>
          </div>
          <div>
            <p className="text-xs font-medium text-gray-500 uppercase">{t('loyalty:fields.endDate')}</p>
            <p className="mt-1 text-sm text-gray-900">
              {program.end_date ? new Date(program.end_date).toLocaleDateString() : '-'}
            </p>
          </div>
          <div>
            <p className="text-xs font-medium text-gray-500 uppercase">{t('loyalty:fields.createdAt')}</p>
            <p className="mt-1 text-sm text-gray-900">
              {new Date(program.created_at).toLocaleDateString()}
            </p>
          </div>
          <div>
            <p className="text-xs font-medium text-gray-500 uppercase">{t('loyalty:fields.programType')}</p>
            <p className="mt-1 text-sm text-gray-900">
              {t(`loyalty:programTypes.${program.program_type}`)}
            </p>
          </div>
        </div>
        {program.terms_and_conditions ? (
          <div className="mt-4 pt-4 border-t border-gray-100">
            <p className="text-xs font-medium text-gray-500 uppercase mb-1">{t('loyalty:fields.termsAndConditions')}</p>
            <p className="text-sm text-gray-700 whitespace-pre-wrap">{program.terms_and_conditions}</p>
          </div>
        ) : null}
      </div>

      {/* Sub-resource tabs */}
      <Tabs defaultValue="earning-rules">
        <TabsList>
          <TabsTrigger value="earning-rules">{t('loyalty:sections.earningRules')}</TabsTrigger>
          <TabsTrigger value="rewards">{t('loyalty:sections.rewards')}</TabsTrigger>
          <TabsTrigger value="tiers">{t('loyalty:sections.tiers')}</TabsTrigger>
          <TabsTrigger value="stamp-cards">{t('loyalty:sections.stampCards')}</TabsTrigger>
        </TabsList>
        <TabsContent value="earning-rules">
          <EarningRulesTab programId={program.id} />
        </TabsContent>
        <TabsContent value="rewards">
          <RewardsTab programId={program.id} />
        </TabsContent>
        <TabsContent value="tiers">
          <TiersTab programId={program.id} />
        </TabsContent>
        <TabsContent value="stamp-cards">
          <StampCardsTab programId={program.id} />
        </TabsContent>
      </Tabs>
    </div>
  )
}

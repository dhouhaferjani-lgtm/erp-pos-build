import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Plus, Edit, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { fetchHealthClaims, deleteHealthClaim } from '../api/healthClaimApi';
import { toast } from 'sonner';
import { OffsetPagination } from '@/components/ui/OffsetPagination';

export function HealthClaimListPage() {
  const { t } = useTranslation(['common', 'parapharmacy']);
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [deleteId, setDeleteId] = useState<string | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['parapharmacy', 'health-claims', page],
    queryFn: () => fetchHealthClaims({ page, per_page: 25 }),
  });

  const deleteMutation = useMutation({
    mutationFn: deleteHealthClaim,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['parapharmacy', 'health-claims'] });
      toast.success(t('parapharmacy:healthClaimDeleted'));
      setDeleteId(null);
    },
    onError: (error: any) => {
      const message =
        error?.response?.data?.error?.message ||
        t('parapharmacy:deleteHealthClaimError');
      toast.error(message);
      setDeleteId(null);
    },
  });

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        {t('common:loading')}
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">{t('parapharmacy:healthClaims')}</h1>
          <p className="text-muted-foreground">
            {t('parapharmacy:healthClaimsDescription')}
          </p>
        </div>
        <Button onClick={() => navigate('/parapharmacy/health-claims/new')}>
          <Plus className="h-4 w-4 mr-2" />
          {t('parapharmacy:addHealthClaim')}
        </Button>
      </div>

      <div className="rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{t('parapharmacy:claim')}</TableHead>
              <TableHead>{t('parapharmacy:claimType')}</TableHead>
              <TableHead>{t('parapharmacy:regulatoryStatus')}</TableHead>
              <TableHead>{t('parapharmacy:disclaimer')}</TableHead>
              <TableHead className="text-end">{t('common:actions')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {data?.data.length === 0 && (
              <TableRow>
                <TableCell colSpan={5} className="text-center text-muted-foreground">
                  {t('common:noData')}
                </TableCell>
              </TableRow>
            )}
            {data?.data.map((healthClaim) => (
              <TableRow key={healthClaim.id}>
                <TableCell className="font-medium max-w-md truncate">
                  {healthClaim.claim}
                </TableCell>
                <TableCell>
                  <code className="text-xs bg-muted px-2 py-1 rounded">
                    {healthClaim.claim_type}
                  </code>
                </TableCell>
                <TableCell>
                  <Badge
                    variant={
                      healthClaim.regulatory_status === 'approved'
                        ? 'default'
                        : healthClaim.regulatory_status === 'pending'
                        ? 'secondary'
                        : 'destructive'
                    }
                  >
                    {t(`parapharmacy:regulatoryStatus.${healthClaim.regulatory_status}`)}
                  </Badge>
                </TableCell>
                <TableCell>
                  {healthClaim.requires_disclaimer ? (
                    <Badge variant="outline">
                      {t('parapharmacy:required')}
                    </Badge>
                  ) : (
                    <span className="text-muted-foreground">—</span>
                  )}
                </TableCell>
                <TableCell className="text-end">
                  <div className="flex items-center justify-end gap-2">
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() =>
                        navigate(`/parapharmacy/health-claims/${healthClaim.id}`)
                      }
                    >
                      <Edit className="h-4 w-4" />
                    </Button>
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => setDeleteId(healthClaim.id)}
                    >
                      <Trash2 className="h-4 w-4" />
                    </Button>
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>

      {data?.meta.pagination && (
        <OffsetPagination
          currentPage={page}
          totalPages={Math.ceil(
            data.meta.pagination.total / data.meta.pagination.per_page
          )}
          onPageChange={setPage}
        />
      )}

      <AlertDialog open={!!deleteId} onOpenChange={() => setDeleteId(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {t('parapharmacy:confirmDeleteHealthClaim')}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {t('parapharmacy:confirmDeleteHealthClaimDescription')}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t('common:cancel')}</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => deleteId && deleteMutation.mutate(deleteId)}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {t('common:delete')}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

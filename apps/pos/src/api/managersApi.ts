import { apiGet } from '@/lib/api';

export interface AuthorizedManager {
  id: string;
  name: string;
}

export async function fetchAuthorizedManagers(): Promise<AuthorizedManager[]> {
  return apiGet<AuthorizedManager[]>('/pos/authorized-managers');
}

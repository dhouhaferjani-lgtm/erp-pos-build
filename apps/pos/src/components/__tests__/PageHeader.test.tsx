import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { PageHeader } from '../PageHeader';

describe('PageHeader', () => {
  it('renders the title', () => {
    render(<PageHeader title="Paramètres" />);
    expect(screen.getByRole('heading', { name: 'Paramètres' })).toBeInTheDocument();
  });

  it('renders a labelled back button on the left and calls onBack', () => {
    const onBack = vi.fn();
    render(<PageHeader title="Ventes" onBack={onBack} backLabel="Retour" />);
    const back = screen.getByLabelText('Retour');
    fireEvent.click(back);
    expect(onBack).toHaveBeenCalledOnce();
  });

  it('omits the back button when onBack is not given', () => {
    render(<PageHeader title="Ventes" backLabel="Retour" />);
    expect(screen.queryByLabelText('Retour')).toBeNull();
  });

  it('renders right-aligned actions', () => {
    render(<PageHeader title="Paramètres" actions={<button>Enregistrer</button>} />);
    expect(screen.getByRole('button', { name: 'Enregistrer' })).toBeInTheDocument();
  });
});

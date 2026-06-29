import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { Drawer } from '../Drawer';

function renderDrawer(props: Partial<React.ComponentProps<typeof Drawer>> = {}) {
  return render(
    <Drawer
      isOpen
      onClose={props.onClose ?? vi.fn()}
      title="Filtres"
      closeLabel="Fermer"
      {...props}
    >
      <p>drawer body</p>
    </Drawer>,
  );
}

describe('Drawer', () => {
  it('renders nothing when closed', () => {
    renderDrawer({ isOpen: false });
    expect(screen.queryByText('Filtres')).not.toBeInTheDocument();
    expect(screen.queryByText('drawer body')).not.toBeInTheDocument();
  });

  it('renders title + children when open as a dialog', () => {
    renderDrawer();
    expect(screen.getByText('Filtres')).toBeInTheDocument();
    expect(screen.getByText('drawer body')).toBeInTheDocument();
    expect(screen.getByRole('dialog')).toHaveAttribute('aria-modal', 'true');
  });

  it('calls onClose when the backdrop is clicked', () => {
    const onClose = vi.fn();
    const { container } = renderDrawer({ onClose });
    // Backdrop is the first absolute-inset element.
    const backdrop = container.querySelector('.absolute.inset-0');
    fireEvent.click(backdrop!);
    expect(onClose).toHaveBeenCalledOnce();
  });

  it('calls onClose on the close button (48px target, labelled)', () => {
    const onClose = vi.fn();
    renderDrawer({ onClose });
    fireEvent.click(screen.getByLabelText('Fermer'));
    expect(onClose).toHaveBeenCalledOnce();
  });

  it('calls onClose on Escape', () => {
    const onClose = vi.fn();
    renderDrawer({ onClose });
    fireEvent.keyDown(window, { key: 'Escape' });
    expect(onClose).toHaveBeenCalledOnce();
  });
});

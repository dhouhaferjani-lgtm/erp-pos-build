import { describe, it, expect, vi } from 'vitest';
import { render, fireEvent } from '@testing-library/react';
import { Avatar } from '../Avatar';
import { Stepper } from '../Stepper';
import { Pill } from '../Pill';

describe('Avatar', () => {
  it('renders initials from the name', () => {
    const { getByText } = render(<Avatar name="Maya Okonkwo" />);
    expect(getByText('MO')).toBeTruthy();
  });
});

describe('Stepper', () => {
  it('fires increment/decrement', () => {
    const inc = vi.fn();
    const dec = vi.fn();
    const { getByLabelText } = render(
      <Stepper value={3} onIncrement={inc} onDecrement={dec} />,
    );
    fireEvent.click(getByLabelText('Augmenter'));
    fireEvent.click(getByLabelText('Diminuer'));
    expect(inc).toHaveBeenCalledOnce();
    expect(dec).toHaveBeenCalledOnce();
  });

  it('disables decrement at min', () => {
    const dec = vi.fn();
    const { getByLabelText } = render(
      <Stepper value={1} min={1} onIncrement={vi.fn()} onDecrement={dec} />,
    );
    const minus = getByLabelText('Diminuer') as HTMLButtonElement;
    expect(minus.disabled).toBe(true);
    fireEvent.click(minus);
    expect(dec).not.toHaveBeenCalled();
  });

  it('shows the formatted display value and fires onValueClick', () => {
    const onValueClick = vi.fn();
    const { getByText } = render(
      <Stepper value={2} display="2,000" onIncrement={vi.fn()} onDecrement={vi.fn()} onValueClick={onValueClick} />,
    );
    const val = getByText('2,000');
    fireEvent.click(val);
    expect(onValueClick).toHaveBeenCalledOnce();
  });
});

describe('Pill', () => {
  it('toggles and reflects selected via aria-pressed', () => {
    const onClick = vi.fn();
    const { getByRole } = render(
      <Pill selected onClick={onClick}>
        Visage
      </Pill>,
    );
    const btn = getByRole('button', { pressed: true });
    fireEvent.click(btn);
    expect(onClick).toHaveBeenCalledOnce();
  });

  it('renders a remove control when onRemove is provided', () => {
    const onRemove = vi.fn();
    const { getByLabelText } = render(
      <Pill onRemove={onRemove}>Marque: Avène</Pill>,
    );
    fireEvent.click(getByLabelText('Retirer'));
    expect(onRemove).toHaveBeenCalledOnce();
  });
});

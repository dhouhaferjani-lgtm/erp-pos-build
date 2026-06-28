import { describe, it, expect, vi } from 'vitest';
import { render, fireEvent } from '@testing-library/react';
import { Tabs } from '../Tabs';
import { Toggle } from '../Toggle';
import { KpiCard } from '../KpiCard';
import { BreakdownBar } from '../BreakdownBar';
import { Divider } from '../Divider';

describe('Tabs', () => {
  it('marks the active tab and fires onChange', () => {
    const onChange = vi.fn();
    const { getByRole } = render(
      <Tabs
        value="desc"
        onChange={onChange}
        tabs={[
          { id: 'desc', label: 'Description' },
          { id: 'equiv', label: 'Équivalents', count: 3 },
        ]}
      />,
    );
    expect(getByRole('tab', { selected: true }).textContent).toContain('Description');
    fireEvent.click(getByRole('tab', { name: /Équivalents/ }));
    expect(onChange).toHaveBeenCalledWith('equiv');
  });
});

describe('Toggle', () => {
  it('reflects checked via aria and toggles', () => {
    const onChange = vi.fn();
    const { getByRole } = render(
      <Toggle checked={false} onChange={onChange} ariaLabel="Consentement marketing" />,
    );
    const sw = getByRole('switch');
    expect(sw.getAttribute('aria-checked')).toBe('false');
    fireEvent.click(sw);
    expect(onChange).toHaveBeenCalledWith(true);
  });
});

describe('KpiCard', () => {
  it('renders label, value and hint', () => {
    const { getByText } = render(<KpiCard label="Ventes" value="1 234,500 DT" hint="Aujourd’hui" />);
    expect(getByText('Ventes')).toBeTruthy();
    expect(getByText('1 234,500 DT')).toBeTruthy();
    expect(getByText('Aujourd’hui')).toBeTruthy();
  });
});

describe('BreakdownBar', () => {
  it('clamps pct and exposes a progressbar', () => {
    const { getByRole } = render(<BreakdownBar label="Espèces" valueText="800,000 DT" pct={150} />);
    const bar = getByRole('progressbar');
    expect(bar.getAttribute('aria-valuenow')).toBe('100');
    expect(bar.getAttribute('aria-label')).toBe('Espèces');
  });
});

describe('Divider', () => {
  it('exposes a separator with orientation', () => {
    const { getByRole } = render(<Divider orientation="vertical" />);
    expect(getByRole('separator').getAttribute('aria-orientation')).toBe('vertical');
  });
});

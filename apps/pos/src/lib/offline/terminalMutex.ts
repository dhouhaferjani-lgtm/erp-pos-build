const terminalLocks = new Map<string, Promise<void>>();

export async function lockTerminal<T>(
  tenantId: string,
  terminalId: string,
  fn: () => Promise<T>,
): Promise<T> {
  const key = `${tenantId}:${terminalId}`;
  const prior = terminalLocks.get(key) ?? Promise.resolve();

  let release!: () => void;
  const current = new Promise<void>((resolve) => {
    release = resolve;
  });
  const tail = prior.then(() => current, () => current);
  terminalLocks.set(key, tail);

  await prior;
  try {
    return await fn();
  } finally {
    release();
    if (terminalLocks.get(key) === tail) {
      terminalLocks.delete(key);
    }
  }
}

export function __resetTerminalLocksForTesting(): void {
  terminalLocks.clear();
}

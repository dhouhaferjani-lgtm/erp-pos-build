import type { LocationNode } from './api'

export interface LocationNodeBranch extends LocationNode {
  children: LocationNodeBranch[]
}

export function buildNodeTree(nodes: LocationNode[]): LocationNodeBranch[] {
  const branches = new Map<string, LocationNodeBranch>()
  nodes.forEach((node) => { branches.set(node.id, { ...node, children: [] }) })

  const roots: LocationNodeBranch[] = []
  branches.forEach((branch) => {
    const parent = branch.parent_id === null ? undefined : branches.get(branch.parent_id)
    if (parent === undefined) roots.push(branch)
    else parent.children.push(branch)
  })

  const sort = (items: LocationNodeBranch[]): void => {
    items.sort((a, b) => a.sort_order - b.sort_order || a.name.localeCompare(b.name))
    items.forEach((item) => { sort(item.children) })
  }
  sort(roots)
  return roots
}

export function flattenVisibleNodes(
  tree: LocationNodeBranch[],
  expanded: ReadonlySet<string>,
): LocationNodeBranch[] {
  const rows: LocationNodeBranch[] = []
  const visit = (branch: LocationNodeBranch): void => {
    rows.push(branch)
    if (expanded.has(branch.id)) branch.children.forEach(visit)
  }
  tree.forEach(visit)
  return rows
}

export function isDescendant(node: LocationNode, possibleAncestor: LocationNode): boolean {
  return node.path.startsWith(`${possibleAncestor.path}/`)
}

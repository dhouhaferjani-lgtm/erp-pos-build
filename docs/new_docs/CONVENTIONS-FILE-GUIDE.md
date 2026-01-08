# Coding Conventions File Management

## The Problem

A 1170-line coding conventions file is too long for effective use:
- Claude Code may not read it all
- Key rules get lost in the noise
- Updates become difficult

## Recommended Structure

Split into **focused files** by domain:

```
docs/conventions/
├── README.md                    # Index + how to use (50 lines max)
├── 01-API-RESPONSES.md          # API response format (~100 lines)
├── 02-AUTHORIZATION.md          # Auth patterns (~100 lines)
├── 03-FRONTEND-COMPONENTS.md    # React patterns (~150 lines)
├── 04-DATABASE.md               # Migrations, naming (~100 lines)
├── 05-MODULE-STRUCTURE.md       # File organization (~100 lines)
├── 06-TYPESCRIPT-TYPES.md       # Type definitions (~100 lines)
├── 07-REACT-QUERY.md            # Data fetching (~100 lines)
├── 08-FORMS.md                  # Form handling (~100 lines)
├── 09-NAVIGATION.md             # Adding pages/routes (~100 lines)
└── 10-TESTING.md                # Test patterns (~100 lines)
```

## CLAUDE.md Integration

Add this section to your project's `CLAUDE.md`:

```markdown
## Coding Conventions

**CRITICAL: Read relevant convention files before implementing.**

Convention files are in `docs/conventions/`. Reference:

| Task | Read These Files |
|------|------------------|
| New API endpoint | 01-API-RESPONSES.md, 02-AUTHORIZATION.md |
| New page/component | 03-FRONTEND-COMPONENTS.md, 09-NAVIGATION.md |
| Database changes | 04-DATABASE.md |
| New module | 05-MODULE-STRUCTURE.md |
| TypeScript types | 06-TYPESCRIPT-TYPES.md |
| Data fetching | 07-REACT-QUERY.md |
| Forms | 08-FORMS.md |
| Tests | 10-TESTING.md |

### Quick Rules (Always Apply)

1. **API responses**: Always use `ApiResponse::success()` wrapper
2. **Authorization**: Apply middleware + policy for every endpoint
3. **Types**: TypeScript types MUST match actual API response
4. **Navigation**: Every new page needs route + nav entry + breadcrumbs
5. **Run verification**: After implementation, run `./scripts/verify.sh`
```

## How to Split the Existing File

Ask Claude Code to:

```
I have a coding conventions file (CODING_CONVENTIONS.md, 1170 lines).
Please split it into focused files following this structure:

docs/conventions/
├── README.md                    # Index with links to each file
├── 01-API-RESPONSES.md          # Everything about API response format
├── 02-AUTHORIZATION.md          # All auth/permission patterns
├── 03-FRONTEND-COMPONENTS.md    # React component patterns
├── 04-DATABASE.md               # Migration and naming conventions
├── 05-MODULE-STRUCTURE.md       # Module file organization
├── 06-TYPESCRIPT-TYPES.md       # Type definition patterns
├── 07-REACT-QUERY.md            # React Query usage
├── 08-FORMS.md                  # Form handling patterns
├── 09-NAVIGATION.md             # How to add new pages
└── 10-TESTING.md                # Testing patterns

Rules:
- Each file should be 50-150 lines max
- Include actual code examples from our codebase
- Create a README.md that links to each file with a summary
- Update CLAUDE.md to reference these files

Start by reading the full conventions file, then create each split file.
```

## Verification

After splitting, verify by asking Claude Code in a new session:

```
I need to add a new API endpoint for listing POS terminals.
What conventions files should I read first?
```

Expected behavior: Claude Code should reference:
- 01-API-RESPONSES.md
- 02-AUTHORIZATION.md
- 05-MODULE-STRUCTURE.md

## Keep It Maintained

1. **Update when patterns change** - Don't let it get stale
2. **Add examples from real code** - Not theoretical patterns
3. **Review quarterly** - Remove outdated sections
4. **Cross-reference** - Each file can link to related files

# Claude Code: Codebase Audit & Report Generation

## Mission

Run a comprehensive audit of the codebase and generate a detailed report file that can be shared for implementation planning.

**Output**: Create `/docs/CODEBASE_AUDIT_REPORT.md` with all findings.

---

## Instructions

Execute each section below, capturing output. Build the report incrementally.

### Step 1: Initialize Report

```bash
mkdir -p docs
cat > docs/CODEBASE_AUDIT_REPORT.md << 'EOF'
# Codebase Audit Report

**Generated**: $(date)
**Purpose**: Provide context for multi-product modular architecture implementation

---

EOF
```

### Step 2: Project Overview

```bash
echo "## 1. Project Overview" >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

# Laravel version
echo "### Laravel Version" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
php artisan --version >> docs/CODEBASE_AUDIT_REPORT.md 2>/dev/null || echo "Could not determine Laravel version"
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

# PHP version
echo "### PHP Version" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
php -v | head -1 >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

# Composer dependencies (key ones)
echo "### Key Dependencies" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```json' >> docs/CODEBASE_AUDIT_REPORT.md
cat composer.json | grep -A 50 '"require"' | head -40 >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md
```

### Step 3: Directory Structure

```bash
echo "## 2. Directory Structure" >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "### Top-Level Structure" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
ls -la | grep -v node_modules | grep -v vendor >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "### App Directory (3 levels)" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
find app -type d -maxdepth 3 | sort >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md
```

### Step 4: Module Structure Analysis

```bash
echo "## 3. Module Structure" >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

# Check if Modules directory exists
if [ -d "app/Modules" ]; then
    echo "### Modules Found: YES" >> docs/CODEBASE_AUDIT_REPORT.md
    echo "" >> docs/CODEBASE_AUDIT_REPORT.md
    
    echo "### Module List" >> docs/CODEBASE_AUDIT_REPORT.md
    echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
    ls -la app/Modules/ >> docs/CODEBASE_AUDIT_REPORT.md
    echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
    echo "" >> docs/CODEBASE_AUDIT_REPORT.md
    
    echo "### Module Internal Structure (first module as example)" >> docs/CODEBASE_AUDIT_REPORT.md
    echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
    FIRST_MODULE=$(ls app/Modules/ | head -1)
    find "app/Modules/$FIRST_MODULE" -type f -name "*.php" | head -20 >> docs/CODEBASE_AUDIT_REPORT.md
    echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
    echo "" >> docs/CODEBASE_AUDIT_REPORT.md
    
    echo "### All Module Service Providers" >> docs/CODEBASE_AUDIT_REPORT.md
    echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
    find app/Modules -name "*ServiceProvider.php" -o -name "*Provider.php" | sort >> docs/CODEBASE_AUDIT_REPORT.md
    echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
else
    echo "### Modules Found: NO" >> docs/CODEBASE_AUDIT_REPORT.md
    echo "" >> docs/CODEBASE_AUDIT_REPORT.md
    echo "No app/Modules directory found. Checking alternative structures..." >> docs/CODEBASE_AUDIT_REPORT.md
    echo "" >> docs/CODEBASE_AUDIT_REPORT.md
    
    echo "### Models Location" >> docs/CODEBASE_AUDIT_REPORT.md
    echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
    find app -name "*.php" -path "*/Models/*" | sort >> docs/CODEBASE_AUDIT_REPORT.md
    echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
fi
echo "" >> docs/CODEBASE_AUDIT_REPORT.md
```

### Step 5: Product/Entity Analysis

```bash
echo "## 4. Product Entity Analysis" >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

# Find Product model
echo "### Product Model Location" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
find app -name "Product.php" -type f >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

# Show Product model contents
echo "### Product Model Contents" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```php' >> docs/CODEBASE_AUDIT_REPORT.md
PRODUCT_FILE=$(find app -name "Product.php" -type f | head -1)
if [ -n "$PRODUCT_FILE" ]; then
    cat "$PRODUCT_FILE" >> docs/CODEBASE_AUDIT_REPORT.md
else
    echo "Product model not found" >> docs/CODEBASE_AUDIT_REPORT.md
fi
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

# Products migration
echo "### Products Table Migration" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```php' >> docs/CODEBASE_AUDIT_REPORT.md
PRODUCTS_MIGRATION=$(find database/migrations -name "*products*" -type f | head -1)
if [ -n "$PRODUCTS_MIGRATION" ]; then
    cat "$PRODUCTS_MIGRATION" >> docs/CODEBASE_AUDIT_REPORT.md
else
    echo "Products migration not found" >> docs/CODEBASE_AUDIT_REPORT.md
fi
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md
```

### Step 6: Company/Tenant Structure

```bash
echo "## 5. Multi-Tenancy Structure" >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

# Find Company model
echo "### Company Model" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```php' >> docs/CODEBASE_AUDIT_REPORT.md
COMPANY_FILE=$(find app -name "Company.php" -type f | head -1)
if [ -n "$COMPANY_FILE" ]; then
    cat "$COMPANY_FILE" >> docs/CODEBASE_AUDIT_REPORT.md
else
    echo "Company model not found" >> docs/CODEBASE_AUDIT_REPORT.md
fi
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

# Find Tenant model if exists
echo "### Tenant Model (if exists)" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```php' >> docs/CODEBASE_AUDIT_REPORT.md
TENANT_FILE=$(find app -name "Tenant.php" -type f | head -1)
if [ -n "$TENANT_FILE" ]; then
    cat "$TENANT_FILE" >> docs/CODEBASE_AUDIT_REPORT.md
else
    echo "No separate Tenant model found" >> docs/CODEBASE_AUDIT_REPORT.md
fi
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

# Check for tenancy package
echo "### Tenancy Configuration" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
if [ -f "config/tenancy.php" ]; then
    cat config/tenancy.php | head -50 >> docs/CODEBASE_AUDIT_REPORT.md
else
    echo "No config/tenancy.php found" >> docs/CODEBASE_AUDIT_REPORT.md
fi
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md
```

### Step 7: Service Providers

```bash
echo "## 6. Service Providers" >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "### App Service Providers" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
ls -la app/Providers/ >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "### AppServiceProvider Contents" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```php' >> docs/CODEBASE_AUDIT_REPORT.md
cat app/Providers/AppServiceProvider.php >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "### Bootstrap Providers (Laravel 11+)" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```php' >> docs/CODEBASE_AUDIT_REPORT.md
if [ -f "bootstrap/providers.php" ]; then
    cat bootstrap/providers.php >> docs/CODEBASE_AUDIT_REPORT.md
else
    echo "No bootstrap/providers.php (Laravel 10 or earlier)" >> docs/CODEBASE_AUDIT_REPORT.md
    echo "" >> docs/CODEBASE_AUDIT_REPORT.md
    echo "Checking config/app.php providers array:" >> docs/CODEBASE_AUDIT_REPORT.md
    grep -A 30 "'providers'" config/app.php >> docs/CODEBASE_AUDIT_REPORT.md
fi
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md
```

### Step 8: Routes Structure

```bash
echo "## 7. Routes Structure" >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "### Routes Files" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
ls -la routes/ >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "### API Routes (first 100 lines)" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```php' >> docs/CODEBASE_AUDIT_REPORT.md
head -100 routes/api.php >> docs/CODEBASE_AUDIT_REPORT.md 2>/dev/null || echo "No routes/api.php"
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

# Check for module routes
echo "### Module Routes (if any)" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
find app/Modules -name "routes.php" -o -name "api.php" 2>/dev/null | head -20 >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md
```

### Step 9: Database Migrations

```bash
echo "## 8. Database Migrations" >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "### All Migrations" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
ls -la database/migrations/ | tail -50 >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "### Key Table Schemas" >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

# Companies table
echo "#### Companies Migration" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```php' >> docs/CODEBASE_AUDIT_REPORT.md
COMPANIES_MIGRATION=$(find database/migrations -name "*companies*" -type f | head -1)
if [ -n "$COMPANIES_MIGRATION" ]; then
    cat "$COMPANIES_MIGRATION" >> docs/CODEBASE_AUDIT_REPORT.md
else
    echo "Companies migration not found" >> docs/CODEBASE_AUDIT_REPORT.md
fi
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

# Vehicles table (if exists)
echo "#### Vehicles Migration (if exists)" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```php' >> docs/CODEBASE_AUDIT_REPORT.md
VEHICLES_MIGRATION=$(find database/migrations -name "*vehicles*" -type f | head -1)
if [ -n "$VEHICLES_MIGRATION" ]; then
    cat "$VEHICLES_MIGRATION" >> docs/CODEBASE_AUDIT_REPORT.md
else
    echo "Vehicles migration not found" >> docs/CODEBASE_AUDIT_REPORT.md
fi
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md
```

### Step 10: Config Files

```bash
echo "## 9. Configuration Files" >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "### Config Directory" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
ls -la config/ >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "### App Config (first 50 lines)" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```php' >> docs/CODEBASE_AUDIT_REPORT.md
head -50 config/app.php >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "### Environment Variables (.env.example)" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
cat .env.example >> docs/CODEBASE_AUDIT_REPORT.md 2>/dev/null || echo "No .env.example found"
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md
```

### Step 11: Frontend Structure

```bash
echo "## 10. Frontend Structure" >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "### Frontend Stack" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```json' >> docs/CODEBASE_AUDIT_REPORT.md
if [ -f "package.json" ]; then
    cat package.json | grep -A 30 '"dependencies"' | head -35 >> docs/CODEBASE_AUDIT_REPORT.md
else
    echo "No package.json found" >> docs/CODEBASE_AUDIT_REPORT.md
fi
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "### Resources Directory" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
find resources -type d -maxdepth 3 | sort >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "### React/Vue Components (if any)" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
find resources -name "*.tsx" -o -name "*.jsx" -o -name "*.vue" 2>/dev/null | head -30 >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "### Tailwind Config (if exists)" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```javascript' >> docs/CODEBASE_AUDIT_REPORT.md
if [ -f "tailwind.config.js" ]; then
    cat tailwind.config.js >> docs/CODEBASE_AUDIT_REPORT.md
elif [ -f "tailwind.config.ts" ]; then
    cat tailwind.config.ts >> docs/CODEBASE_AUDIT_REPORT.md
else
    echo "No Tailwind config found" >> docs/CODEBASE_AUDIT_REPORT.md
fi
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md
```

### Step 12: Testing Structure

```bash
echo "## 11. Testing Structure" >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "### Test Directories" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
find tests -type d -maxdepth 3 | sort >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "### Test Framework" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
if [ -f "phpunit.xml" ]; then
    echo "PHPUnit detected" >> docs/CODEBASE_AUDIT_REPORT.md
    head -20 phpunit.xml >> docs/CODEBASE_AUDIT_REPORT.md
fi
if [ -f "pest.php" ] || grep -q "pestphp" composer.json 2>/dev/null; then
    echo "Pest detected" >> docs/CODEBASE_AUDIT_REPORT.md
fi
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "### Sample Test File" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```php' >> docs/CODEBASE_AUDIT_REPORT.md
SAMPLE_TEST=$(find tests -name "*Test.php" -type f | head -1)
if [ -n "$SAMPLE_TEST" ]; then
    head -50 "$SAMPLE_TEST" >> docs/CODEBASE_AUDIT_REPORT.md
else
    echo "No test files found" >> docs/CODEBASE_AUDIT_REPORT.md
fi
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md
```

### Step 13: Existing Vertical/Type Logic

```bash
echo "## 12. Existing Vertical/Type Logic" >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "### Search for Vertical-Related Code" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
grep -r "vertical" app/ --include="*.php" -l 2>/dev/null | head -20 >> docs/CODEBASE_AUDIT_REPORT.md
grep -r "business_type" app/ --include="*.php" -l 2>/dev/null | head -20 >> docs/CODEBASE_AUDIT_REPORT.md
grep -r "company_type" app/ --include="*.php" -l 2>/dev/null | head -20 >> docs/CODEBASE_AUDIT_REPORT.md
grep -r "BusinessType" app/ --include="*.php" -l 2>/dev/null | head -20 >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "### Enums (if any)" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
find app -name "*.php" -path "*/Enums/*" | sort >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

# Show enum contents if found
echo "### Enum Contents" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```php' >> docs/CODEBASE_AUDIT_REPORT.md
for ENUM in $(find app -name "*.php" -path "*/Enums/*" | head -5); do
    echo "// $ENUM" >> docs/CODEBASE_AUDIT_REPORT.md
    cat "$ENUM" >> docs/CODEBASE_AUDIT_REPORT.md
    echo "" >> docs/CODEBASE_AUDIT_REPORT.md
done
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md
```

### Step 14: CLAUDE.md and Documentation

```bash
echo "## 13. Existing Documentation" >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "### CLAUDE.md (if exists)" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```markdown' >> docs/CODEBASE_AUDIT_REPORT.md
if [ -f "CLAUDE.md" ]; then
    cat CLAUDE.md >> docs/CODEBASE_AUDIT_REPORT.md
else
    echo "No CLAUDE.md found" >> docs/CODEBASE_AUDIT_REPORT.md
fi
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "### README.md" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```markdown' >> docs/CODEBASE_AUDIT_REPORT.md
if [ -f "README.md" ]; then
    head -100 README.md >> docs/CODEBASE_AUDIT_REPORT.md
else
    echo "No README.md found" >> docs/CODEBASE_AUDIT_REPORT.md
fi
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "### Docs Directory" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
find docs -type f 2>/dev/null | head -20 >> docs/CODEBASE_AUDIT_REPORT.md || echo "No docs directory"
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md
```

### Step 15: Summary & Analysis

```bash
echo "## 14. Summary & Analysis" >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "### Quick Stats" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "Total PHP files: $(find app -name '*.php' | wc -l)" >> docs/CODEBASE_AUDIT_REPORT.md
echo "Total Migrations: $(ls database/migrations/*.php 2>/dev/null | wc -l)" >> docs/CODEBASE_AUDIT_REPORT.md
echo "Total Tests: $(find tests -name '*Test.php' 2>/dev/null | wc -l)" >> docs/CODEBASE_AUDIT_REPORT.md
echo "Modules: $(ls app/Modules 2>/dev/null | wc -l || echo 0)" >> docs/CODEBASE_AUDIT_REPORT.md
echo '```' >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "### Key Findings (TO BE FILLED BY ANALYSIS)" >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md
echo "After reviewing the above, summarize:" >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md
echo "1. **Module Architecture**: [Describe current state]" >> docs/CODEBASE_AUDIT_REPORT.md
echo "2. **Multi-tenancy Approach**: [Schema-based / Column-based / None]" >> docs/CODEBASE_AUDIT_REPORT.md
echo "3. **Product Model Structure**: [Describe current schema]" >> docs/CODEBASE_AUDIT_REPORT.md
echo "4. **Existing Vertical Logic**: [Any / None]" >> docs/CODEBASE_AUDIT_REPORT.md
echo "5. **Test Coverage**: [Good / Partial / None]" >> docs/CODEBASE_AUDIT_REPORT.md
echo "6. **Frontend Stack**: [React / Vue / Blade / Inertia]" >> docs/CODEBASE_AUDIT_REPORT.md
echo "7. **Ready for Multi-Product**: [Yes / Needs Work]" >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md

echo "---" >> docs/CODEBASE_AUDIT_REPORT.md
echo "" >> docs/CODEBASE_AUDIT_REPORT.md
echo "*Report generated by Claude Code audit script*" >> docs/CODEBASE_AUDIT_REPORT.md
```

### Step 16: Final Output

```bash
echo ""
echo "=============================================="
echo "AUDIT COMPLETE"
echo "=============================================="
echo ""
echo "Report generated: docs/CODEBASE_AUDIT_REPORT.md"
echo ""
echo "File size: $(wc -c < docs/CODEBASE_AUDIT_REPORT.md) bytes"
echo "Line count: $(wc -l < docs/CODEBASE_AUDIT_REPORT.md) lines"
echo ""
echo "Next steps:"
echo "1. Review the report"
echo "2. Fill in the 'Key Findings' section"
echo "3. Share with planning team"
echo ""
```

---

## After Running This Audit

Once the report is generated:

1. **Review** the `docs/CODEBASE_AUDIT_REPORT.md` file
2. **Fill in** the "Key Findings" section based on what you see
3. **Share** the report back so I can customize the implementation prompt

The report will tell us:
- Current module structure (if any)
- How products are modeled
- Multi-tenancy approach
- Frontend stack
- What needs to be built vs. refactored

# Troubleshooting & FAQ

Common issues and their solutions.

---

## Migration Issues

### "Class 'Condoedge\Ai\Wrappers\AI' not found"

**Error:** `Class 'Condoedge\Ai\Wrappers\AI' not found`

**Cause:** Using deprecated import statement after refactoring.

**Solution:**

Update your import statement:

```php
// Old (deprecated)
use Condoedge\Ai\Wrappers\AI;

// New (current)
use Condoedge\Ai\Facades\AI;
```

The API is unchanged - only the import needs updating. See the [Migration Guide](https://github.com/your-repo/MIGRATION-GUIDE.md) for complete details.

---

## Connection Issues

### Neo4j Connection Refused

**Error:** `Connection refused on bolt://localhost:7687`

**Solutions:**

1. **Check Neo4j is running:**
```bash
docker ps | grep neo4j
```

2. **Verify port accessibility:**
```bash
telnet localhost 7687
```

3. **Check credentials in `.env`:**
```env
NEO4J_URI=bolt://localhost:7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=your-password
```

4. **Try HTTP instead of Bolt:**
```env
NEO4J_URI=http://localhost:7474
```

5. **Check Neo4j logs:**
```bash
docker logs neo4j
```

---

### Qdrant Connection Failed

**Error:** `Could not connect to Qdrant at localhost:6333`

**Solutions:**

1. **Verify Qdrant is running:**
```bash
docker ps | grep qdrant
```

2. **Check port binding:**
```bash
curl http://localhost:6333/health
```

3. **Verify `.env` configuration:**
```env
QDRANT_HOST=localhost
QDRANT_PORT=6333
```

4. **Check Qdrant logs:**
```bash
docker logs qdrant
```

---

## API Key Issues

### OpenAI Invalid API Key

**Error:** `Invalid API key provided`

**Solutions:**

1. **Check key format (starts with `sk-`):**
```env
OPENAI_API_KEY=sk-your-actual-key-here
```

2. **Remove extra spaces:**
```bash
# Wrong
OPENAI_API_KEY= sk-key-here

# Correct
OPENAI_API_KEY=sk-key-here
```

3. **Clear config cache:**
```bash
php artisan config:clear
```

4. **Verify in OpenAI dashboard:**
   - Visit https://platform.openai.com/api-keys
   - Check key status

---

### Anthropic API Key Issues

**Error:** `Authentication failed`

**Solutions:**

1. **Verify key format (starts with `sk-ant-`):**
```env
ANTHROPIC_API_KEY=sk-ant-your-key-here
```

2. **Check API key permissions**

3. **Clear cache:**
```bash
php artisan config:clear
php artisan cache:clear
```

---

## Ingestion Issues

### Entity Not Ingested

**Problem:** `AI::ingest()` returns errors

**Solutions:**

1. **Check entity implements Nodeable:**
```php
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;
}
```

2. **Verify entity configuration exists:**
```php
// config/entities.php
'Customer' => [
    'graph' => [...],
    'vector' => [...]
]
```

3. **Check entity has getId() method:**
```php
public function getId(): string|int
{
    return $this->id;
}
```

4. **Examine error details:**
```php
$status = AI::ingest($customer);
if (!empty($status['errors'])) {
    dd($status['errors']);
}
```

---

### Embedding Generation Failed

**Error:** `Failed to generate embedding`

**Solutions:**

1. **Check embed_fields contain data:**
```php
// Entity must have these fields populated
'embed_fields' => ['name', 'description']
```

2. **Verify fields are not empty:**
```php
$customer->name = 'John Doe';  // Not null or empty
$customer->description = 'Customer description';
```

3. **Check API key is valid**

4. **Test embedding directly:**
```php
$vector = AI::embed("Test text");
```

---

## Performance Issues

### Slow Ingestion

**Problem:** Ingesting entities takes too long

**Solutions:**

1. **Use batch operations:**
```php
// Slow
foreach ($customers as $customer) {
    AI::ingest($customer);
}

// Fast
AI::ingestBatch($customers->toArray());
```

2. **Increase timeouts:**
```env
QDRANT_TIMEOUT=60
AI_QUERY_TIMEOUT=60
```

3. **Queue ingestion:**
```php
IngestEntityJob::dispatch($customer);
```

4. **Reduce embed_fields:**
```php
// Only embed essential fields
'embed_fields' => ['name']  // Instead of all fields
```

---

### Vector Search Slow

**Problem:** Similarity search is slow

**Solutions:**

1. **Reduce search limit:**
```php
AI::searchSimilar($query, ['limit' => 5]);  // Instead of 50
```

2. **Increase score threshold:**
```php
AI::searchSimilar($query, ['scoreThreshold' => 0.8]);  // Filter more
```

3. **Index Qdrant collection properly**

4. **Check Qdrant resources**

---

## Configuration Issues

### Config Not Loading

**Problem:** Changes to config don't take effect

**Solutions:**

1. **Clear config cache:**
```bash
php artisan config:clear
php artisan cache:clear
```

2. **Verify config file location:**
```bash
ls -la config/ai.php
```

3. **Check for syntax errors:**
```bash
php artisan config:cache
```

---

### Environment Variables Not Working

**Problem:** `.env` values not applied

**Solutions:**

1. **Clear cache:**
```bash
php artisan config:clear
```

2. **Check `.env` file location (project root)**

3. **Verify variable names match exactly**

4. **No spaces around `=`:**
```env
# Wrong
NEO4J_PASSWORD = password

# Correct
NEO4J_PASSWORD=password
```

---

## Common Errors

### "Entity must implement Nodeable"

**Error:** `Entity must implement Nodeable interface`

**Solution:**
```php
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;

class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    public function getId(): string|int
    {
        return $this->id;
    }
}
```

---

### "Collection does not exist"

**Error:** `Collection 'customers' does not exist`

**Solution:**

1. **Create collection manually:**
```php
$qdrant = new QdrantStore(config('ai.qdrant'));
$qdrant->createCollection('customers', 1536);
```

2. **Or let system auto-create on first upsert**

---

### "Node label invalid"

**Error:** `Invalid label name`

**Solution:**

Labels must be alphanumeric:
```php
// Wrong
'label' => 'My-Customer-Type'

// Correct
'label' => 'Customer'
```

---

## FAQ

### Q: Can I use both OpenAI and Anthropic?

**A:** Yes! Use OpenAI for embeddings and Anthropic for LLM:
```env
AI_EMBEDDING_PROVIDER=openai
AI_LLM_PROVIDER=anthropic
```

---

### Q: How do I handle rate limits?

**A:** Implement retry logic and use queues:
```php
try {
    AI::ingest($customer);
} catch (\Exception $e) {
    if (str_contains($e->getMessage(), 'rate limit')) {
        IngestEntityJob::dispatch($customer)->delay(now()->addSeconds(60));
    }
}
```

---

### Q: Can I use a different graph database?

**A:** Yes! Implement `GraphStoreInterface`:
```php
class ArangoDBStore implements GraphStoreInterface
{
    // Implement interface methods
}
```

---

### Q: How do I migrate data between environments?

**A:** Export from one, import to another:
```bash
# Export Neo4j
neo4j-admin dump --database=neo4j --to=backup.dump

# Import to new instance
neo4j-admin load --from=backup.dump --database=neo4j
```

---

### Q: What are the costs?

**A:** Depends on usage:
- **OpenAI:** ~$0.0001/1K tokens (embeddings), ~$0.03/1K tokens (GPT-4)
- **Anthropic:** ~$0.015/1K tokens (Claude)
- **Neo4j:** Free (Community), $65+/mo (Enterprise)
- **Qdrant:** Free (self-hosted), $0.50/GB/mo (cloud)

---

### Q: How do I debug RAG context?

**A:** Log the context:
```php
$context = AI::retrieveContext($question);
Log::info('RAG Context', $context);
```

---

## Getting Help

1. **Check documentation:** All sections
2. **Review logs:** `storage/logs/laravel.log`
3. **Test connections:** Use test scripts
4. **Check infrastructure:** Neo4j, Qdrant status
5. **Verify configuration:** `.env` and `config/ai.php`

---

## Still Stuck?

- Review [Architecture](/docs/{{version}}/internals/architecture)
- Check [Configuration Reference](/docs/{{version}}/foundations/configuration)
- See [Examples](/docs/{{version}}/usage/examples)

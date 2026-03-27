# Extended 50 Questions QA Plan

## Questions 31-50: Creative Real-World Questions

### I. Personal Life Questions (things residents actually ask) — User 302
31. "When is my neighbor's birthday?" — Need to find co-residents of same building, then dob field on Contact
32. "What's my intercom code?" — Specific property on Unit (intercom_code) - tests property concept
33. "How much do I pay in condo fees?" — Invoice amount for user's unit - financial traversal
34. "Can I see my invoices?" — Possessive + Invoice entity via unit path

### J. French Language (bilingual users) — User 13
35. "Combien d'unités avons-nous?" — French count query
36. "Qui sont les propriétaires de l'unité 3?" — French with unit name lookup
37. "Montre-moi les membres du conseil d'administration" — French board members

### K. Aggregation / Analytics — User 13
38. "What is the total share percentage of all units?" — SUM aggregation on property
39. "Which union has the most units?" — GROUP BY + COUNT + ORDER
40. "Show the 5 largest units by square footage" — ORDER BY + LIMIT

### L. Absence / Negation (what's missing) — User 13
41. "Which units have no insurance?" — NULL check on insurance fields
42. "Show contacts without an email" — Missing data across entities
43. "Which units don't have any owners?" — NOT EXISTS pattern with ContactUnit

### M. Date / Time Questions — User 13
44. "Show meetings from 2021" — Year-based filter
45. "Are there any upcoming events?" — Temporal: future dates
46. "When was the last meeting held?" — ORDER BY date DESC LIMIT 1

### N. Multi-Hop / Complex Traversals — User 302
47. "What building is my unit in?" — User→Contact→ContactUnit→Unit→Building
48. "Which union does my unit belong to?" — Same + Union hop
49. "Do I have parking?" — Possessive + Extra entity with type filter

### O. Conversational / Edge Cases — User 1512
50. "Tell me about the system" — No query needed, should respond from project context

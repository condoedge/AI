# 30 Questions QA Plan for CondoEdge AI

## Test Configuration
- **User 302** (Hervé Pilorge, team 3, has unit 3 in SDC du 1155): Personal/possessive queries
- **User 13** (Julie Rousseau, team 3, property manager): Operational queries
- **User 1512** (Tavelli Ezequiel, team 1, admin): Cross-team/admin queries

## Questions by Domain

### A. Personal/Possessive (Resident Perspective) — User 302
Tests: User→Contact→ContactUnit→Unit path, possessive detection, user_entity_paths

1. "Can I get details about my unit?" — Core possessive query, unit details
2. "Who are the other owners of my unit?" — Co-owner lookup via ContactUnit
3. "What is my insurance information?" — Property concept (insurance → Unit properties)
4. "What vehicles are registered in my unit?" — Unit→Vehicle relationship
5. "What is my share percentage?" — Specific property on Unit
6. "When does my water heater expire?" — Computed concept (water_heater_year + 10 years)

### B. Unit/Building/Contact Queries (Manager Perspective) — User 13
Tests: Entity lookup, name matching, relationship traversal

7. "Who lives in unit 3?" — Unit by name, ContactUnit→Contact traversal
8. "How many units are in SDC du 1155 De Maisonneuve Est?" — Count by union name
9. "Show me all contacts for unit 3" — Unit→ContactUnit→Contact
10. "Which units have expired owner insurance?" — Scope: insurance_not_valid
11. "List all buildings" — Simple entity listing
12. "How many units do we manage?" — Count with team scope

### C. Financial Queries — User 13
Tests: Financial entity access, fund concepts, invoice lookup

13. "How many invoices are there?" — Simple count
14. "Show me unpaid invoices" — Status-based filtering
15. "What are the funds for SDC du 1155?" — Union→Fund relationship
16. "How many bills are pending approval?" — Bill status filtering

### D. People & Team Queries — User 1512
Tests: Name resolution, team membership, board members

17. "Can you give me the members of condoedge team?" — Team→User via CURRENT_TEAM
18. "Is Bassem in my team?" — Name resolution + team check
19. "Show me the board members" — BoardMember entity
20. "Who is the president of the board?" — BoardMember with bm_role filter

### E. Communication/Tasks — User 13
Tests: Task, discussion, announcement entities

21. "How many open tasks are there?" — Task count with status filter
22. "Show me recent announcements" — Announcement listing
23. "How many discussion channels exist?" — Channel count

### F. Maintenance/Suppliers — User 13
Tests: Supplier, maintenance, work order entities

24. "List all suppliers" — Simple supplier listing
25. "How many maintenance schedules are active?" — Maintenance count

### G. Meetings/Voting — User 13
Tests: Meeting, motion, voter entities

26. "How many meetings have been held?" — Meeting count
27. "Show me the motions" — Motion listing

### H. Cross-Entity Traversals — User 13
Tests: Complex multi-hop queries

28. "How many contacts have expired insurance on their units?" — Contact→ContactUnit→Unit + scope
29. "Which units have pets?" — Unit→Pet relationship
30. "Show me units with vehicles" — Unit→Vehicle relationship

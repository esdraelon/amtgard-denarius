# Global categories FK cutover checklist

- [x] M-01 Persistence + rules: KingdomCategoryRuleRecord categoryId, repos, delete KingdomCustomCategory*, KingdomCategoryAssigner → CategoryCatalog
- [x] M-02 Ledger + publication: review queue/service, synchronizer, recategorizer, PublicationCandidateLine categoryId, CategoryLabelStage, pattern flows
- [x] M-03 HTTP + UI: ManagerController, typeahead category_id, KingdomScopedCategorySearch, ReviewCategoryValidator
- [x] M-04 Tests: CategoryCatalogFixture, categoryId migration in tests, integration schema expectations (partial — some suites still adjusting)
- [x] M-05 Gates: `./vendor/bin/phpunit` green

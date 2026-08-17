<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\ApiManifest;

final readonly class DynamicEntityApiManifest
{
    public function __construct(
        public string $typesUrl,                      // GET /api/v1/dynamic-entity/types
        public string $fieldDefinitionsUrl,           // GET/POST /api/v1/field/definitions
        public string $fieldDefinitionUrl,            // GET/PUT/DELETE /api/v1/field/definitions/{id}
        public string $constraintsUrl,                // GET /api/v1/dynamic-entity/constraints
        public string $recordsUrl,                    // pattern: GET/POST /api/v1/dynamic-entity/{type}/records
        public string $recordUrl,                     // pattern: GET/PATCH/DELETE /api/v1/dynamic-entity/records/{id}
        public string $recordsByTypeUrl,              // pattern: GET/POST /api/v1/dynamic-entity/{type}/records
        public string $typeUrl,                       // GET/PATCH/DELETE /api/v1/dynamic-entity/types/{id}
        public string $typesCreateUrl,                // POST /api/v1/dynamic-entity/types
        public string $fieldDefinitionsReorderUrl,    // PATCH /api/v1/field/definitions/reorder
        public string $toolbarNaviGraphUrl,           // GET /navi/trees/navi.toolbar.dynamic-entity/graph
        public string $formTypesUrl = '',             // GET /api/v1/field/form-types
        public string $typeByAliasUrl = '',           // pattern: GET /api/v1/dynamic-entity/types/{alias}
    ) {
    }
}

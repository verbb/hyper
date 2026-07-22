import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import type { ScreenshotSetupContext } from '@verbb/docs-screenshots/types';

export type HyperDocsFixture = {
    fieldId: number;
    fieldHandle: string;
    settingsRoute: string;
    entryEditRoute: string;
    urlHandle: string;
    entryHandle: string;
};

const fixtureDir = dirname(fileURLToPath(import.meta.url));
const seedScript = readFileSync(join(fixtureDir, 'seed-docs-field.php'), 'utf8');

/**
 * Seed the Hyper Demo field + Demo entry used by feature-tour screenshots.
 */
export async function seedHyperDocsFixture(context: ScreenshotSetupContext): Promise<HyperDocsFixture> {
    const output = await context.runCraftScript(seedScript, { label: 'seed-hyper-docs-field' });
    const fixture = JSON.parse(output.trim()) as HyperDocsFixture;

    if (!fixture.fieldId || !fixture.settingsRoute || !fixture.entryEditRoute) {
        throw new Error(`Invalid Hyper docs fixture payload: ${output}`);
    }

    return fixture;
}

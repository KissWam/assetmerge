<?php
/**
 * AssetMerge — massive action processor for the READ-ONLY preview action.
 *
 * Registered in hook.php as 'GlpiPlugin\AssetMerge\PreviewProcessor:preview'.
 * GLPI 12 MassiveAction splits on single ':' -> processor = this class,
 * action = 'preview'. It then calls these two static methods:
 *   showMassiveActionsSubForm(MassiveAction $ma): bool
 *   processMassiveActionsForOneItemtype(MassiveAction $ma, CommonDBTM $item, array $ids)
 *
 * NO WRITE OPERATIONS. The report is computed with SELECT only, stored in
 * the PHP session, and rendered by front/merge.preview.php.
 */

namespace GlpiPlugin\AssetMerge;

use CommonDBTM;
use Dropdown;
use MassiveAction;
use Session;

final class PreviewProcessor
{
    /**
     * Show the sub-form asking the operator which record to KEEP (target).
     *
     * @param MassiveAction $ma
     * @return bool true when we rendered our own form
     */
    public static function showMassiveActionsSubForm(MassiveAction $ma): bool
    {
        if ($ma->getAction() !== 'preview') {
            return false;
        }

        $input = $ma->getInput();
        // In specialize/subform stage, selected ids are normalized to
        // $input['items'][$itemtype][$id] = $id; raw 'item' is already unset.
        $ids = [];
        if (isset($input['items']) && is_array($input['items'])) {
            foreach ($input['items'] as $type => $typeIds) {
                if ($type !== 'Computer' || !is_array($typeIds)) {
                    continue;
                }
                foreach (array_keys($typeIds) as $id) {
                    $id = (int)$id;
                    if ($id > 0) {
                        $ids[] = $id;
                    }
                }
            }
        }
        if (count($ids) < 2) {
            echo "<div class='alert alert-warning'>" .
                __('Select at least two computers to preview a merge.', 'assetmerge') . "</div>";
            // Return false so GLPI's showSubForm() renders the standard Post
            // button via showDefaultSubForm() in the modal footer position.
            return false;
        }

        global $DB;
        $options = [];
        foreach ($ids as $id) {
            foreach ($DB->request([
                'SELECT' => ['id', 'name', 'serial', 'is_dynamic'],
                'FROM'   => 'glpi_computers',
                'WHERE'  => ['id' => $id],
            ]) as $row) {
                $label = sprintf(
                    '#%d — %s — SN:%s — %s',
                    $id,
                    $row['name'],
                    $row['serial'] !== '' ? $row['serial'] : '(none)',
                    (int)$row['is_dynamic'] === 1 ? 'dynamic' : 'static'
                );
                $options[$id] = $label;
            }
        }

        echo "<div class='alert alert-info'>" .
            __('Choose the record to KEEP. It must be the agent-created (dynamic) record with the agent binding; otherwise the preview will reject the selection.', 'assetmerge') .
            "</div>";
        echo "<div class='form-field'>";
        echo "<label for='item_items_id'>" . __('Record to keep (target)', 'assetmerge') . "</label>";
        Dropdown::showFromArray('item_items_id', $options, [
            'value' => 0,
            'display' => true,
        ]);
        echo "</div>";
        // GLPI 12 renders the submit button (via showDefaultSubForm) directly
        // after our fields in the same flow — there is no footer container.
        // A blank line pushes the button below the select, otherwise it
        // overlaps the dropdown. Core's own actions do the same with <br>.
        echo "<br>";

        // Return FALSE so GLPI 12 MassiveAction::showSubForm() calls
        // showDefaultSubForm(), which places the standard Post button in the
        // modal's proper footer position. Returning TRUE with a self-echoed
        // button renders it inside the field area and makes it overlap the
        // modal bottom edge. Our custom fields above are already echoed and
        // remain visible regardless of the return value.
        return false;
    }

    /**
     * Compute and store the preview report. No writes to GLPI data tables.
     *
     * @param MassiveAction $ma
     * @param CommonDBTM    $item
     * @param array         $ids
     * @return void
     */
    public static function processMassiveActionsForOneItemtype(
        MassiveAction $ma,
        CommonDBTM $item,
        array $ids
    ): void {
        if ($ma->getAction() !== 'preview') {
            return;
        }

        if (!Session::haveRight('computer', UPDATE) || !Session::haveRight('computer', DELETE)) {
            $ma->itemDone($item::class, $ids, MassiveAction::ACTION_NORIGHT);
            $ma->addMessage($item->getErrorMessage(ERROR_RIGHT));
            return;
        }

        $input = $ma->getInput();
        $targetId = isset($input['item_items_id']) ? (int)$input['item_items_id'] : 0;
        if ($targetId <= 0) {
            $ma->itemDone($item::class, $ids, MassiveAction::ACTION_KO);
            $ma->addMessage(__('No target record selected.', 'assetmerge'));
            return;
        }

        if (count($ids) < 2) {
            $ma->itemDone($item::class, $ids, MassiveAction::ACTION_KO);
            $ma->addMessage(__('Select at least two computers to preview a merge.', 'assetmerge'));
            return;
        }

        // $ids contains ALL selected records, including the chosen target.
        // PreviewEngine rejects a record that is both target and source, so
        // the target must be removed before building the source list.
        $sourceIds = array_values(array_filter(
            $ids,
            static function ($id) use ($targetId) {
                return (int)$id !== $targetId;
            }
        ));
        if (count($sourceIds) < 1) {
            $ma->itemDone($item::class, $ids, MassiveAction::ACTION_KO);
            $ma->addMessage(__('At least one source record is required in addition to the target.', 'assetmerge'));
            return;
        }

        global $DB;
        $engine = new PreviewEngine($DB);
        $report = $engine->analyze($targetId, $sourceIds);

        // Store report in session for the preview page (read-only render).
        $_SESSION['assetmerge_preview'] = $report;
        $_SESSION['assetmerge_preview']['requested_by'] = $_SESSION['glpiname'] ?? '';
        $_SESSION['assetmerge_preview']['generated_at'] = date('Y-m-d H:i:s');

        // Set the report page as the post-action redirect. Do NOT call
        // $ma->addMessage() on the success path: GLPI 12 renders every entry
        // in results['messages'] as a red "error" toast, so a success message
        // there is displayed as a false failure. itemDone(ACTION_OK) alone
        // produces the correct "operation successful" confirmation.
        global $CFG_GLPI;
        $ma->setRedirect($CFG_GLPI['root_doc'] . '/plugins/assetmerge/front/merge.preview.php');
        $ma->itemDone($item::class, $ids, MassiveAction::ACTION_OK);
    }
}

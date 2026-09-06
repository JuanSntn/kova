import { Switch } from "@/components/ui/switch";
import { Label } from "@/components/ui/label";

interface SettingSwitchProps {
    id: string;
    label: string;
    description?: string;
    checked: boolean;
    disabled?: boolean;
    onCheckedChange: (
        checked: boolean,
    ) => void;
}

export function SettingSwitch({
    id,
    label,
    description,
    checked,
    disabled,
    onCheckedChange,
}: SettingSwitchProps) {
    return (
        <div className="flex items-start justify-between gap-4 py-3">
            <div className="space-y-1">
                <Label
                    htmlFor={id}
                    className="cursor-pointer text-sm font-medium"
                >
                    {label}
                </Label>

                {description && (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>

            <Switch
                id={id}
                checked={checked}
                disabled={disabled}
                onCheckedChange={
                    onCheckedChange
                }
            />
        </div>
    );
}
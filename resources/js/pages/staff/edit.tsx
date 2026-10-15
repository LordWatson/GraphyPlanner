import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index, update } from '@/routes/staff';
import { PageHeader } from '@/components/page';

type Option = { value: string; label: string };

type Member = {
    id: number;
    name: string;
    email: string;
    role: string | null;
    role_label: string | null;
};

export default function StaffEdit({
    member,
    roles,
}: {
    member: Member;
    roles: Option[];
}) {
    return (
        <>
            <Head title={`Edit ${member.name}`} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-8 px-4 py-6 md:px-8 md:py-9">
                <PageHeader
                    title={<>Edit {member.name}</>}
                    description="Update this staff member's name and role"
                />

                <Form
                    {...update.form(member.id)}
                    options={{ preserveScroll: true }}
                    className="border-border bg-card grid gap-6 rounded-2xl border p-4 shadow-sm sm:p-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    defaultValue={member.name}
                                    required
                                    autoFocus
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label>Email</Label>
                                <p className="text-muted-foreground text-sm">
                                    {member.email}
                                </p>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="role">Role</Label>
                                <Select
                                    name="role"
                                    defaultValue={member.role ?? undefined}
                                >
                                    <SelectTrigger id="role" className="w-full">
                                        <SelectValue placeholder="Select a role" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {roles.map((role) => (
                                            <SelectItem
                                                key={role.value}
                                                value={role.value}
                                            >
                                                {role.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.role} />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button disabled={processing}>
                                    Save changes
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

StaffEdit.layout = {
    breadcrumbs: [
        { title: 'Staff', href: index() },
        { title: 'Edit member', href: index() },
    ],
};

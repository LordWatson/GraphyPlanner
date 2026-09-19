import { Form, Head } from '@inertiajs/react';
import Heading from '@/components/heading';
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

type Option = { value: string; label: string };

type Member = {
    id: number;
    name: string;
    email: string;
    role: string | null;
    role_label: string | null;
};

export default function StaffEdit({ member, roles }: { member: Member; roles: Option[] }) {
    return (
        <>
            <Head title={`Edit ${member.name}`} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4">
                <Heading title={`Edit ${member.name}`} description="Update this staff member's name and role" />

                <Form {...update.form(member.id)} options={{ preserveScroll: true }} className="space-y-6">
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input id="name" name="name" defaultValue={member.name} required autoFocus />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label>Email</Label>
                                <p className="text-sm text-muted-foreground">{member.email}</p>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="role">Role</Label>
                                <Select name="role" defaultValue={member.role ?? undefined}>
                                    <SelectTrigger id="role" className="w-full">
                                        <SelectValue placeholder="Select a role" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {roles.map((role) => (
                                            <SelectItem key={role.value} value={role.value}>
                                                {role.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.role} />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button disabled={processing}>Save changes</Button>
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

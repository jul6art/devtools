/** A client of the project's own, which only shares the kit's name. */
export class ApiClient {
  get(path: string): Promise<unknown> {
    return fetch(path).then((response) => response.json());
  }
}
